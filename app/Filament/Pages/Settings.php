<?php

namespace App\Filament\Pages;

use App\Filament\Navigation\Groups;
use App\Jobs\ImportMessageTextsJob;
use App\Models\PsoMessageText;
use App\Models\User;
use App\Support\Settings\AppSettings;
use App\Support\Settings\SettingsFileStore;
use App\Support\SysCompare\DefinitionsCsv;
use App\Support\SysCompare\Exceptions\InvalidDefinitionsFile;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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

    /** An import still marked importing after this long is taken to have stopped (lost job or worker down). */
    public const IMPORT_STALE_AFTER_MINUTES = 30;

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

    /** An import is running: the record says importing and it is not yet stale. */
    public function messageTextsBusy(): bool
    {
        return ($this->messageTextsRecord()['status'] ?? null) === 'importing' && ! $this->messageTextsStale();
    }

    /** Still marked importing long after the upload, so the job is lost or the worker is down: Upload and Clear unlock. */
    public function messageTextsStale(): bool
    {
        $record = $this->messageTextsRecord();
        if (($record['status'] ?? null) !== 'importing') {
            return false;
        }

        $uploadedAt = $record['uploaded_at'] ?? null;
        if (! is_string($uploadedAt) || $uploadedAt === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($uploadedAt)->lte(now()->subMinutes(self::IMPORT_STALE_AFTER_MINUTES));
        } catch (Throwable) {
            return true;
        }
    }

    public function messageTextsUploaderName(): ?string
    {
        $userId = $this->messageTextsRecord()['uploaded_by'] ?? null;

        return $userId === null ? null : User::query()->find($userId)?->name;
    }

    public function uploadParamDefinitionsAction(): Action
    {
        return Action::make('uploadParamDefinitions')
            ->label('Upload ParamDefinitions.csv')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Upload ParamDefinitions.csv')
            ->modalDescription('Columns Parameter, Definition, Note and Basis. It replaces the stored file, if there is one.')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                FileUpload::make('file')
                    ->label('ParamDefinitions.csv')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->maxSize(10240)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, Schema $schema): void {
                $file = $data['file'] ?? null;
                if (! $file instanceof TemporaryUploadedFile) {
                    throw new RuntimeException('No file was uploaded.');
                }

                $fileName = basename(str_replace('\\', '/', $file->getClientOriginalName()));

                $parameterCount = $this->withLocalCopy($file, static function (string $localPath) use ($fileName, $schema): int {
                    try {
                        $rows = DefinitionsCsv::parse($localPath, $fileName);
                    } catch (InvalidDefinitionsFile $exception) {
                        throw ValidationException::withMessages([$schema->getStatePath().'.file' => $exception->getMessage()]);
                    }

                    app(SettingsFileStore::class)->putFromLocal(SettingsFileStore::PARAM_DEFINITIONS, $localPath);

                    return count($rows);
                });

                $file->delete();

                $userId = auth()->id();

                app(AppSettings::class)->put(AppSettings::PARAM_DEFINITIONS, [
                    'file_name' => $fileName,
                    'parameter_count' => $parameterCount,
                    'uploaded_by' => $userId,
                    'uploaded_at' => now()->toIso8601String(),
                ], $userId);

                Notification::make()->success()->title('Parameter definitions saved')->send();
            });
    }

    public function removeParamDefinitionsAction(): Action
    {
        return Action::make('removeParamDefinitions')
            ->label('Remove')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->modalDescription('PSO Sys File Compare goes back to the built-in definitions.')
            ->visible(fn (): bool => $this->paramDefinitionsRecord() !== null)
            ->action(static function (): void {
                app(SettingsFileStore::class)->delete(SettingsFileStore::PARAM_DEFINITIONS);
                app(AppSettings::class)->forget(AppSettings::PARAM_DEFINITIONS);

                Notification::make()->success()->title('Parameter definitions removed')->send();
            });
    }

    /** @return array<mixed>|null */
    public function paramDefinitionsRecord(): ?array
    {
        return app(AppSettings::class)->paramDefinitions();
    }

    public function paramDefinitionsUploaderName(): ?string
    {
        $userId = $this->paramDefinitionsRecord()['uploaded_by'] ?? null;

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

    /** Livewire's temporary uploads live on r2, so putFromLocal is handed a local scratch copy. */
    private function storeUpload(TemporaryUploadedFile $file, string $key): void
    {
        try {
            $this->withLocalCopy($file, static function (string $localPath) use ($key): void {
                app(SettingsFileStore::class)->putFromLocal($key, $localPath);
            });
        } finally {
            $file->delete();
        }
    }

    /**
     * Streams a temporary upload (which lives on r2, so getRealPath() is not a local path)
     * to a local scratch file, hands that path to the callback and removes it afterwards.
     *
     * @template TResult
     *
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    private function withLocalCopy(TemporaryUploadedFile $file, Closure $callback): mixed
    {
        $localPath = tempnam(sys_get_temp_dir(), 'settings-upload-');
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

            return $callback($localPath);
        } finally {
            @unlink($localPath);
        }
    }
}
