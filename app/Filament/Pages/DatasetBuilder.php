<?php

namespace App\Filament\Pages;

use App\Filament\Navigation\Groups;
use App\Filament\Resources\EnvironmentResource;
use App\Models\Environment;
use App\Support\DatasetBuilder\DatasetGenerator;
use App\Support\DatasetBuilder\DatasetSpecRepository;
use App\Support\DatasetBuilder\InvalidDatasetSpec;
use App\Support\GatewayUpload\GatewayUploadQueue;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use UnitEnum;

/**
 * Dataset Builder, stage 1: a synthetic LOAD of regions, locations, skills,
 * resource types, resources, shifts and breaks (no activities) from a seed
 * spec in resources/datasets, as of "now". Stage 1R: the matching modelling
 * data (.arp) beside it, sent first. The briefs and the field names are in
 * docs/dataset-builder.
 */
class DatasetBuilder extends Page
{
    protected static string|UnitEnum|null $navigationGroup = Groups::SETUP;

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $slug = 'dataset-builder';

    protected static ?string $title = 'Dataset Builder';

    protected string $view = 'filament.pages.dataset-builder';

    public ?array $data = [];

    public ?array $sendData = [];

    /** @var array<string, mixed>|null DatasetSummary::toArray() of the last run */
    public ?array $summary = null;

    /** The file the last run wrote; set only on the server. */
    #[Locked]
    public ?string $generatedPath = null;

    /** The modelling data (.arp) the last run wrote, if the spec asks for it; set only on the server. */
    #[Locked]
    public ?string $modellingPath = null;

    public function mount(): void
    {
        $options = app(DatasetSpecRepository::class)->options();

        $this->form->fill([
            'spec' => array_key_first($options),
            'now' => CarbonImmutable::now()->startOfMinute()->format('Y-m-d H:i:s'),
            'seed' => null,
        ]);

        $this->sendForm->fill(['environment_id' => null, 'skip_webhook' => true]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Generate')
                    ->description('Stage 1: regions, locations, skills, resource types, resources, shifts and breaks, and the matching modelling data (.arp) when the spec asks for it. No activities yet.')
                    ->schema([
                        Select::make('spec')
                            ->label('Seed spec')
                            ->options(static fn (): array => app(DatasetSpecRepository::class)->options())
                            ->native(false)
                            ->required(),
                        DateTimePicker::make('now')
                            ->label('Now')
                            ->helperText(static fn (): string => 'In '.config('app.timezone').'; the file is written in UTC. Day 0 is this moment\'s date in each region\'s own time zone. Leave it at the current time to make the dataset current.')
                            ->seconds(false)
                            ->required(),
                        TextInput::make('seed')
                            ->label('Seed override')
                            ->helperText('Leave blank to use the spec\'s own seed. The same seed, spec and now give the same file.')
                            ->integer(),
                    ])
                    ->columns(3)
                    ->footerActions([
                        Action::make('generate')
                            ->label('Generate')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->action(fn () => $this->generate()),
                    ]),
            ]);
    }

    public function sendForm(Schema $form): Schema
    {
        return $form
            ->statePath('sendData')
            ->schema([
                Section::make('Send to an environment')
                    ->description('Queues a Gateway upload of the modelling data, then of the LOAD, which is sent only if the modelling data went in. Their progress and logs show on that environment\'s Tools tab.')
                    ->schema([
                        Select::make('environment_id')
                            ->label('Environment')
                            ->options(static fn (): array => Environment::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->native(false)
                            ->required(),
                        Toggle::make('skip_webhook')
                            ->label('Skip webhook broadcast')
                            ->helperText('On by default for these loads: the file goes as it is, with no webhook Broadcast added.'),
                    ])
                    ->columns(2)
                    ->footerActions([
                        Action::make('send')
                            ->label('Send')
                            ->icon(Heroicon::OutlinedArrowUpOnSquare)
                            ->requiresConfirmation()
                            ->modalDescription('This replaces the dataset in the chosen environment with the generated LOAD (and its modelling data, sent first).')
                            ->action(fn () => $this->send()),
                    ]),
            ]);
    }

    public function generate(): void
    {
        $state = $this->form->getState();

        try {
            $spec = app(DatasetSpecRepository::class)->load((string) $state['spec']);

            if (filled($state['seed'] ?? null)) {
                $spec = $spec->withSeed((int) $state['seed']);
            }

            // The picker works in the app's time zone; the generator turns it into UTC.
            $now = new DateTimeImmutable((string) $state['now'], new DateTimeZone((string) config('app.timezone')));
            $result = app(DatasetGenerator::class)->generate($spec, $now);
        } catch (InvalidDatasetSpec $exception) {
            Notification::make()->danger()->title('The seed spec has a problem')->body($exception->getMessage())->persistent()->send();

            return;
        }

        $this->generatedPath = $result->path;
        $this->modellingPath = $result->modellingPath;
        $this->summary = $result->summary;

        Notification::make()->success()->title("Generated {$result->summary['file']}")->send();
    }

    public function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => $this->generatedFile() !== null)
            ->action(fn (): ?BinaryFileResponse => ($path = $this->generatedFile()) === null ? null : response()->download($path));
    }

    public function downloadModellingAction(): Action
    {
        return Action::make('downloadModelling')
            ->label('Download .arp')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->generatedFile($this->modellingPath) !== null)
            ->action(fn (): ?BinaryFileResponse => ($path = $this->generatedFile($this->modellingPath)) === null ? null : response()->download($path));
    }

    public function send(): void
    {
        $path = $this->generatedFile();

        if ($path === null) {
            Notification::make()->warning()->title('Generate a file first')->send();

            return;
        }

        $state = $this->sendForm->getState();
        $environment = Environment::query()->find($state['environment_id']);

        if ($environment === null) {
            Notification::make()->danger()->title('Choose one of your environments')->send();

            return;
        }

        $files = [];

        // Modelling first: the LOAD's Source_Data refers to it. Never a webhook on modelling data.
        if (($modelling = $this->generatedFile($this->modellingPath)) !== null) {
            $files[] = ['path' => $modelling, 'filename' => basename($modelling), 'skip_webhook' => true];
        }

        $files[] = ['path' => $path, 'filename' => basename($path), 'skip_webhook' => (bool) $state['skip_webhook']];

        try {
            app(GatewayUploadQueue::class)->queueInOrder($environment, $files, auth()->id());
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('Not sent')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(count($files) === 1 ? "Queued for {$environment->name}" : "Queued for {$environment->name}: modelling data, then the LOAD")
            ->body('Follow it on the environment\'s Tools tab.')
            ->actions([
                Action::make('open')
                    ->label('Open Tools tab')
                    ->url(EnvironmentResource::getUrl('environmentTools', ['record' => $environment])),
            ])
            ->send();
    }

    /** The last generated LOAD (or the given file of the last run), only while it still exists under storage/app/datasets. */
    public function generatedFile(?string $path = null): ?string
    {
        $path = func_num_args() === 0 ? $this->generatedPath : $path;

        if ($path === null) {
            return null;
        }

        try {
            $real = realpath($path);
            $root = realpath(storage_path('app/datasets'));
        } catch (Throwable) {
            return null;
        }

        return $real !== false && $root !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR) ? $real : null;
    }
}
