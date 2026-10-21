<?php

namespace App\Filament\Pages;

use App\Filament\Navigation\Groups;
use App\Jobs\ImportMessageTextsJob;
use App\Models\PsoMessageText;
use App\Models\User;
use App\Support\Settings\AppSettings;
use App\Support\Settings\SettingsFileStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;
use UnitEnum;

/** App-wide settings every signed-in user can change: message texts, Load audit defaults, parameter definitions and the Google Routes cache. */
class Settings extends Page
{
    protected static string|UnitEnum|null $navigationGroup = Groups::ADMIN;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'settings';

    protected static ?string $title = 'Settings';

    protected string $view = 'filament.pages.settings';

    public ?array $auditDefaults = [];

    public function mount(): void
    {
        $settings = app(AppSettings::class);

        $this->form->fill([
            'noise_event_ids' => $settings->noiseEventIds(),
            'complete_grace_hours' => $settings->completeGraceHours(),
            'flag_activities' => $settings->flagActivities(),
            'flag_deletions' => $settings->flagDeletions(),
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('auditDefaults')
            ->schema([
                Section::make('Load audit defaults')
                    ->description('These are the starting values on the Load audit page. Any of them can still be changed for one run.')
                    ->schema([
                        TextInput::make('complete_grace_hours')
                            ->label('Finished work kept for (hours)')
                            ->helperText('Finished activities younger than this are expected in a LOAD. IFS Cloud keeps them 24 hours.')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(168)
                            ->required(),
                        TextInput::make('noise_event_ids')
                            ->label('Noise event ids')
                            ->helperText('Event ids kept in the counts but left out of the lanes and findings')
                            ->regex('/^\s*(\d+\s*(,\s*\d+\s*)*)?$/'),
                        TextInput::make('flag_activities')
                            ->label('Flag an appointment with this many activities')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->required(),
                        TextInput::make('flag_deletions')
                            ->label('Flag a resource with this many deletions')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function saveAuditDefaults(): void
    {
        $state = $this->form->getState();

        app(AppSettings::class)->saveLoadAuditDefaults(
            (string) ($state['noise_event_ids'] ?? ''),
            (int) $state['complete_grace_hours'],
            (int) $state['flag_activities'],
            (int) $state['flag_deletions'],
            auth()->id(),
        );

        $this->form->fill([
            'noise_event_ids' => app(AppSettings::class)->noiseEventIds(),
            'complete_grace_hours' => (int) $state['complete_grace_hours'],
            'flag_activities' => (int) $state['flag_activities'],
            'flag_deletions' => (int) $state['flag_deletions'],
        ]);

        Notification::make()->success()->title('Load audit defaults saved')->send();
    }

    public function uploadMessageTextsAction(): Action
    {
        return Action::make('uploadMessageTexts')
            ->label('Upload language export')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->disabled(fn (): bool => $this->messageTextsBusy())
            ->modalHeading('Upload language export')
            ->modalDescription('An IFS language export (XML, or a ZIP holding one). Every language in the file replaces the texts already loaded for it.')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                FileUpload::make('file')
                    ->label('Language export')
                    ->acceptedFileTypes(['text/xml', 'application/xml', 'application/zip', 'application/x-zip-compressed'])
                    ->maxSize(51200)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $file = $data['file'] ?? null;
                if (! $file instanceof TemporaryUploadedFile) {
                    throw new RuntimeException('No file was uploaded.');
                }

                $fileName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $key = SettingsFileStore::messageTextsKey($this->uploadExtension($file));
                $this->storeUpload($file, $key);

                $uploadedAt = now()->toIso8601String();
                $userId = auth()->id();

                app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, [
                    'status' => 'importing',
                    'file_name' => $fileName,
                    'uploaded_by' => $userId,
                    'uploaded_at' => $uploadedAt,
                    'languages' => [],
                    'rows' => 0,
                    'error' => null,
                ], $userId);

                try {
                    ImportMessageTextsJob::dispatch($key, $fileName, $userId, $uploadedAt);
                } catch (Throwable $exception) {
                    $this->recordQueueFailure($key, $fileName, $userId, $uploadedAt, $exception);

                    return;
                }

                Notification::make()->success()->title('Importing '.$fileName)->send();
            });
    }

    public function clearMessageTextsAction(): Action
    {
        return Action::make('clearMessageTexts')
            ->label('Clear message texts')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->modalDescription('Every loaded language is removed. The Load audit then shows message ids without their texts.')
            ->visible(fn (): bool => $this->messageTextsRecord() !== null || $this->messageTextsLoaded() !== [])
            ->disabled(fn (): bool => $this->messageTextsBusy())
            ->action(static function (): void {
                PsoMessageText::query()->truncate();
                app(AppSettings::class)->forget(AppSettings::MESSAGE_TEXTS);

                Notification::make()->success()->title('Message texts cleared')->send();
            });
    }

    /** @return array<string, int> language => row count */
    public function messageTextsLoaded(): array
    {
        return PsoMessageText::query()
            ->toBase()
            ->selectRaw('language_id, count(*) as text_count')
            ->groupBy('language_id')
            ->orderBy('language_id')
            ->pluck('text_count', 'language_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return array<mixed>|null */
    public function messageTextsRecord(): ?array
    {
        return app(AppSettings::class)->messageTexts();
    }

    public function messageTextsBusy(): bool
    {
        return ($this->messageTextsRecord()['status'] ?? null) === 'importing';
    }

    public function messageTextsUploaderName(): ?string
    {
        $userId = $this->messageTextsRecord()['uploaded_by'] ?? null;

        return $userId === null ? null : User::query()->find($userId)?->name;
    }

    /** The job never ran, so its failed() hook will not either: record the failure here and drop the stored upload. */
    private function recordQueueFailure(string $key, string $fileName, ?int $userId, string $uploadedAt, Throwable $exception): void
    {
        $error = 'The import could not be queued: '.$exception->getMessage();

        app(AppSettings::class)->put(AppSettings::MESSAGE_TEXTS, [
            'status' => 'failed',
            'file_name' => $fileName,
            'uploaded_by' => $userId,
            'uploaded_at' => $uploadedAt,
            'languages' => [],
            'rows' => 0,
            'error' => $error,
        ], $userId);

        try {
            app(SettingsFileStore::class)->delete($key);
        } catch (Throwable $deleteException) {
            Log::warning('Message texts upload cleanup failed', ['object_key' => $key, 'error' => $deleteException->getMessage()]);
        }

        Notification::make()->danger()->title('The import could not be queued')->body($exception->getMessage())->send();
    }

    private function uploadExtension(TemporaryUploadedFile $file): string
    {
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (in_array($extension, ['xml', 'zip'], true)) {
            return $extension;
        }

        return str_contains($file->getMimeType(), 'zip') ? 'zip' : 'xml';
    }

    /**
     * Livewire's temporary uploads live on r2, so the file is streamed to a local
     * scratch copy first: putFromLocal needs a real local path.
     */
    private function storeUpload(TemporaryUploadedFile $file, string $key): void
    {
        $localPath = tempnam(sys_get_temp_dir(), 'message-texts-');
        if ($localPath === false) {
            throw new RuntimeException('The upload could not be copied.');
        }

        try {
            $source = $file->readStream();
            $destination = fopen($localPath, 'wb');
            if (! is_resource($source) || $destination === false) {
                throw new RuntimeException('The upload could not be copied.');
            }
            try {
                stream_copy_to_stream($source, $destination);
            } finally {
                fclose($destination);
                fclose($source);
            }

            app(SettingsFileStore::class)->putFromLocal($key, $localPath);
        } finally {
            @unlink($localPath);
            $file->delete();
        }
    }
}
