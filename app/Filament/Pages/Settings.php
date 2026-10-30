<?php

namespace App\Filament\Pages;

use App\Enums\BroadcastAllocationType;
use App\Enums\BroadcastPlanType;
use App\Filament\Navigation\Groups;
use App\Jobs\ImportMessageTextsJob;
use App\Models\PsoMessageText;
use App\Models\User;
use App\Support\ScheduleData\BroadcastSettings;
use App\Support\Settings\AppSettings;
use App\Support\Settings\InvalidKbFile;
use App\Support\Settings\KbFiles;
use App\Support\Settings\SettingsFileStore;
use App\Support\SysCompare\DefinitionsCsv;
use App\Support\SysCompare\Exceptions\InvalidDefinitionsFile;
use App\Tools\Contracts\ToolSetupException;
use App\Tools\LoadAudit\PsoToolsCheckout;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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

/** App-wide settings every signed-in user can change: message texts, Load audit defaults, Gateway upload broadcast defaults, map tiles and timeouts, parameter definitions and the Google Routes cache. */
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

    public ?array $broadcastDefaults = [];

    public ?array $connectionDefaults = [];

    /** Timeout fields: name => [label, help]. */
    private const TIMEOUT_FIELDS = [
        'default' => ['Other pso-services calls', 'Any pso-services call without its own timeout below.'],
        'session' => ['Gateway logon and logoff', 'Logon goes through pso-services to PSO, so it needs more than a plain call.'],
        'appointment' => ['Appointment calls', 'Request, check, accept and decline: pso-services waits on PSO for the offers.'],
        'routes' => ['Google Routes check', 'One chunk of up to 200 trips. Plan travels keeps at most 270 seconds of margin whatever this is.'],
        'reference_values' => ['Reference values', 'The gateway returns the whole dataset body; pso-services streams and parses it.'],
    ];

    public function mount(): void
    {
        $settings = app(AppSettings::class);

        $this->form->fill([
            'noise_event_ids' => $settings->noiseEventIds(),
            'complete_grace_hours' => $settings->completeGraceHours(),
            'flag_activities' => $settings->flagActivities(),
            'flag_deletions' => $settings->flagDeletions(),
        ]);

        $this->broadcastDefaultsForm->fill(BroadcastSettings::defaults()->toFormState());
        $this->connectionDefaultsForm->fill($this->connectionFormState());
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
                            ->helperText('Event ids kept in the counts but left out of the lanes and findings. Leave blank to treat no event id as noise. The shipped list is '.implode(', ', explode(',', AppSettings::SHIPPED_NOISE_EVENT_IDS)).'.')
                            ->regex('/^\s*(\d+\s*(,\s*\d+\s*)*)?$/'),
                        TextInput::make('flag_activities')
                            ->label('Flag an appointment with this many activities')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->required(),
                        TextInput::make('flag_deletions')
                            ->label('Flag an input with this many deletions')
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

    public function broadcastDefaultsForm(Schema $form): Schema
    {
        return $form
            ->statePath('broadcastDefaults')
            ->schema([
                Section::make('Gateway upload broadcast defaults')
                    ->description('The webhook Broadcast added to a LOAD sent through Gateway upload starts from these values. Any of them can still be changed for one upload. A CHANGE file never gets it.')
                    ->schema([
                        TextInput::make('broadcast_id')
                            ->label('Broadcast ID')
                            ->maxLength(255)
                            ->required(),
                        Select::make('broadcast_plan_type')
                            ->label('Plan Type')
                            ->native(false)
                            ->options(BroadcastPlanType::class)
                            ->required(),
                        TextInput::make('broadcast_minimum_plan_quality')
                            ->label('Minimum Plan Quality')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(),
                        Select::make('broadcast_allocation_type')
                            ->label('Allocation Type')
                            ->native(false)
                            ->options(BroadcastAllocationType::class)
                            ->required(),
                        TextInput::make('broadcast_maximum_wait')
                            ->label('Maximum Wait')
                            ->helperText('An ISO 8601 duration, such as PT1M. The longest PSO waits for the minimum plan quality before it broadcasts anyway.')
                            ->required(),
                        TextInput::make('broadcast_maximum_frequency')
                            ->label('Maximum Frequency')
                            ->helperText('An ISO 8601 duration, such as PT10M. The minimum gap between two broadcasts.')
                            ->required(),
                        TagsInput::make('broadcast_required_tables')
                            ->label('Required Tables')
                            ->helperText('Sent as the required_tables parameter, joined with commas. Table names only (letters, digits and underscores).')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function saveBroadcastDefaults(): void
    {
        $settings = BroadcastSettings::validated($this->broadcastDefaultsForm->getState(), $error, $errorField);

        if ($settings === null) {
            throw ValidationException::withMessages(["broadcastDefaults.{$errorField}" => $error]);
        }

        app(AppSettings::class)->saveGatewayBroadcastDefaults($settings->toArray(), auth()->id());

        $this->broadcastDefaultsForm->fill($settings->toFormState());

        Notification::make()->success()->title('Gateway upload broadcast defaults saved')->send();
    }

    public function connectionDefaultsForm(Schema $form): Schema
    {
        $timeoutInputs = [];

        foreach (self::TIMEOUT_FIELDS as $name => [$label, $help]) {
            $timeoutInputs[] = TextInput::make("timeout_{$name}")
                ->label($label)
                ->helperText($help.' Shipped: '.app(AppSettings::class)->shippedTimeout($name).' s.')
                ->integer()
                ->minValue(1)
                ->maxValue(3600)
                ->suffix('s')
                ->required();
        }

        return $form
            ->statePath('connectionDefaults')
            ->schema([
                Section::make('Customer map tiles')
                    ->description('The map on the dashboard. Any Leaflet tile URL works; a provider that needs a key takes it inside the URL.')
                    ->schema([
                        TextInput::make('map_tiles_url')
                            ->label('Tile URL')
                            ->helperText('With the {z}, {x} and {y} placeholders ({s} for a subdomain, {r} for retina). The shipped one is Esri World Street Map, which needs no key.')
                            ->maxLength(1000)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('map_tiles_subdomains')
                            ->label('Subdomains')
                            ->helperText('The letters {s} cycles through, such as abc. Blank when the URL has no {s}.')
                            ->maxLength(20)
                            ->regex('/^[a-z0-9]*$/'),
                        TextInput::make('map_tiles_max_zoom')
                            ->label('Maximum zoom')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(22)
                            ->required(),
                        TextInput::make('map_tiles_attribution')
                            ->label('Attribution')
                            ->helperText('Shown on the map. Plain text and links only, such as Tiles &copy; <a href="https://www.esri.com/">Esri</a>.')
                            ->maxLength(1000)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Timeouts')
                    ->description('How long the app waits for pso-services before it gives up. A change applies to the next call.')
                    ->schema($timeoutInputs)
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function saveConnectionDefaults(): void
    {
        $state = $this->connectionDefaultsForm->getState();

        $url = trim((string) $state['map_tiles_url']);

        if (! preg_match('#^https://#i', $url) || ! str_contains($url, '{z}') || ! str_contains($url, '{x}') || ! str_contains($url, '{y}')) {
            throw ValidationException::withMessages(['connectionDefaults.map_tiles_url' => 'The tile URL must start with https:// and hold the {z}, {x} and {y} placeholders.']);
        }

        $attribution = trim((string) $state['map_tiles_attribution']);

        if (! self::isSafeAttribution($attribution)) {
            throw ValidationException::withMessages(['connectionDefaults.map_tiles_attribution' => 'The attribution can hold plain text and <a href="https://...">links</a> only.']);
        }

        $timeouts = [];

        foreach (array_keys(self::TIMEOUT_FIELDS) as $name) {
            $timeouts[$name] = (int) $state["timeout_{$name}"];
        }

        app(AppSettings::class)->saveConnectionDefaults([
            'url' => $url,
            'subdomains' => (string) ($state['map_tiles_subdomains'] ?? ''),
            'max_zoom' => (int) $state['map_tiles_max_zoom'],
            'attribution' => $attribution,
        ], $timeouts, auth()->id());

        $this->connectionDefaultsForm->fill($this->connectionFormState());

        Notification::make()->success()->title('Map tiles and timeouts saved')->send();
    }

    public function resetConnectionDefaultsAction(): Action
    {
        return Action::make('resetConnectionDefaults')
            ->label('Reset to shipped values')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(static fn (): bool => app(AppSettings::class)->get(AppSettings::MAP_TILES) !== null
                || app(AppSettings::class)->get(AppSettings::TIMEOUTS) !== null)
            ->action(function (): void {
                app(AppSettings::class)->forget(AppSettings::MAP_TILES);
                app(AppSettings::class)->forget(AppSettings::TIMEOUTS);

                $this->connectionDefaultsForm->fill($this->connectionFormState());

                Notification::make()->success()->title('Map tiles and timeouts reset')->send();
            });
    }

    /**
     * The attribution is written into the map as HTML for every user, so it may
     * hold only text, character references and plain https links.
     */
    public static function isSafeAttribution(string $attribution): bool
    {
        $withoutLinks = preg_replace('#<a href="https://[^"<>\s]+">([^<>]*)</a>#i', '$1', $attribution);

        return $withoutLinks !== null && ! str_contains($withoutLinks, '<') && ! str_contains($withoutLinks, '>');
    }

    /** @return array<string, mixed> */
    private function connectionFormState(): array
    {
        $settings = app(AppSettings::class);
        $tiles = $settings->mapTiles();
        $state = [
            'map_tiles_url' => $tiles['url'],
            'map_tiles_subdomains' => $tiles['subdomains'],
            'map_tiles_max_zoom' => $tiles['max_zoom'],
            'map_tiles_attribution' => $tiles['attribution'],
        ];

        foreach ($settings->timeouts() as $name => $seconds) {
            $state["timeout_{$name}"] = $seconds;
        }

        return $state;
    }

    public function resetBroadcastDefaultsAction(): Action
    {
        return Action::make('resetBroadcastDefaults')
            ->label('Reset to shipped values')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(static fn (): bool => app(AppSettings::class)->gatewayBroadcastDefaults() !== null)
            ->action(function (): void {
                app(AppSettings::class)->forget(AppSettings::GATEWAY_BROADCAST);

                $this->broadcastDefaultsForm->fill(BroadcastSettings::defaults()->toFormState());

                Notification::make()->success()->title('Gateway upload broadcast defaults reset')->send();
            });
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

    public function uploadReleaseNotesAction(): Action
    {
        return Action::make('uploadReleaseNotes')
            ->label('Upload release notes PDF')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Upload IFS Public Release Notes')
            ->modalDescription('The Public Release Notes PDF from IFS. It is read into the release-notes watchlist the Load audit uses, replacing the stored one or the one shipped with the tools.')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                FileUpload::make('file')
                    ->label('Public Release Notes (PDF)')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(51200)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, Schema $schema): void {
                $file = $data['file'] ?? null;
                if (! $file instanceof TemporaryUploadedFile) {
                    throw new RuntimeException('No file was uploaded.');
                }

                $fileName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $result = $this->buildKbFile($file, $schema, 'releaseNotesBuilder', '.md', SettingsFileStore::RELEASE_NOTES);

                $userId = auth()->id();
                app(AppSettings::class)->put(AppSettings::RELEASE_NOTES, [
                    'file_name' => $fileName,
                    'build' => $result['build'] ?? null,
                    'date' => $result['date'] ?? null,
                    'fixes' => (int) ($result['fixes'] ?? 0),
                    'kept' => (int) ($result['kept'] ?? 0),
                    'uploaded_by' => $userId,
                    'uploaded_at' => now()->toIso8601String(),
                ], $userId);

                Notification::make()->success()->title('Release notes read: build '.($result['build'] ?? '?'))
                    ->body(number_format((int) ($result['kept'] ?? 0)).' of '.number_format((int) ($result['fixes'] ?? 0)).' fixes are in the watchlist.')->send();
            });
    }

    public function removeReleaseNotesAction(): Action
    {
        return Action::make('removeReleaseNotes')
            ->label('Use the shipped watchlist')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->modalDescription('The uploaded release notes are removed and the Load audit goes back to the watchlist shipped with the tools.')
            ->visible(fn (): bool => $this->releaseNotesRecord() !== null)
            ->action(static function (): void {
                app(SettingsFileStore::class)->delete(SettingsFileStore::RELEASE_NOTES);
                app(AppSettings::class)->forget(AppSettings::RELEASE_NOTES);

                Notification::make()->success()->title('Back to the shipped release-notes watchlist')->send();
            });
    }

    public function uploadParamReferenceAction(): Action
    {
        return Action::make('uploadParamReference')
            ->label('Upload Parameters page')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Upload the IFS docs Parameters page')
            ->modalDescription('In a browser, open docs.ifs.com/pso, Operations, Administration Guide, System Data, Parameters, and save it as "Webpage, HTML Only". It becomes the parameter catalog (defaults and descriptions) for the Load audit and Sys compare.')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                FileUpload::make('file')
                    ->label('Parameters page (.html)')
                    ->acceptedFileTypes(['text/html', 'application/xhtml+xml'])
                    ->maxSize(20480)
                    ->storeFiles(false)
                    ->required(),
                TextInput::make('pso_version')
                    ->label('PSO release the page is for')
                    ->placeholder('6.18')
                    ->regex('/^\d+\.\d+$/')
                    ->required(),
            ])
            ->action(function (array $data, Schema $schema): void {
                $file = $data['file'] ?? null;
                if (! $file instanceof TemporaryUploadedFile) {
                    throw new RuntimeException('No file was uploaded.');
                }

                $fileName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $result = $this->buildKbFile($file, $schema, 'paramsBuilder', '.csv', SettingsFileStore::PARAM_REFERENCE);

                $userId = auth()->id();
                app(AppSettings::class)->put(AppSettings::PARAM_REFERENCE, [
                    'file_name' => $fileName,
                    'rows' => (int) ($result['rows'] ?? 0),
                    'pso_version' => trim((string) $data['pso_version']),
                    'uploaded_by' => $userId,
                    'uploaded_at' => now()->toIso8601String(),
                ], $userId);

                Notification::make()->success()->title('Parameter catalog saved')
                    ->body(number_format((int) ($result['rows'] ?? 0)).' parameters for PSO '.trim((string) $data['pso_version']).'.')->send();
            });
    }

    public function removeParamReferenceAction(): Action
    {
        return Action::make('removeParamReference')
            ->label('Use the shipped catalog')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->requiresConfirmation()
            ->modalDescription('The uploaded parameter catalog is removed and the tools go back to the one shipped with them.')
            ->visible(fn (): bool => $this->paramReferenceRecord() !== null)
            ->action(static function (): void {
                app(SettingsFileStore::class)->delete(SettingsFileStore::PARAM_REFERENCE);
                app(AppSettings::class)->forget(AppSettings::PARAM_REFERENCE);

                Notification::make()->success()->title('Back to the shipped parameter catalog')->send();
            });
    }

    /** @return array<mixed>|null */
    public function releaseNotesRecord(): ?array
    {
        return app(AppSettings::class)->releaseNotes();
    }

    /** @return array<mixed>|null */
    public function paramReferenceRecord(): ?array
    {
        return app(AppSettings::class)->paramReference();
    }

    public function kbUploaderName(?array $record): ?string
    {
        $userId = $record['uploaded_by'] ?? null;

        return $userId === null ? null : User::query()->find($userId)?->name;
    }

    /**
     * Runs a pso-tools kb builder on the upload and stores what it writes; a file the builder refuses is a form error.
     *
     * @return array<string, string> the builder's RESULT fields
     */
    private function buildKbFile(TemporaryUploadedFile $file, Schema $schema, string $builder, string $outputExtension, string $key): array
    {
        try {
            return $this->withLocalCopy($file, static function (string $localPath) use ($schema, $builder, $outputExtension, $key): array {
                $checkout = PsoToolsCheckout::fromConfig();
                $input = $localPath.'.'.($outputExtension === '.md' ? 'pdf' : 'html');
                $output = $localPath.'-built'.$outputExtension;
                copy($localPath, $input);
                try {
                    try {
                        $result = KbFiles::build($checkout, $checkout->{$builder}(), $input, $output);
                    } catch (InvalidKbFile|ToolSetupException $exception) {
                        throw ValidationException::withMessages([$schema->getStatePath().'.file' => $exception->getMessage()]);
                    }
                    app(SettingsFileStore::class)->putFromLocal($key, $output);

                    return $result;
                } finally {
                    @unlink($input);
                    @unlink($output);
                }
            });
        } finally {
            $file->delete();
        }
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
