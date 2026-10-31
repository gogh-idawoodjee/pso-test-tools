<?php

namespace App\Filament\Pages\Activity;

use App\Enums\ReferenceEntityType;
use App\Enums\ReferenceValueOrigin;
use App\Filament\BasePages\PSOActivityBasePage;
use App\Filament\Resources\DatasetReferenceValueResource;
use App\Models\Dataset;
use App\Models\DatasetReferenceValue;
use App\Support\GeocodeHelper;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JsonException;

class GenerateActivities extends PSOActivityBasePage
{
    // View
    protected string $view = 'filament.pages.activity-generate-activities';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-document-plus';

    // Page Information
    protected static ?string $title = 'Generate Activities';

    protected static ?string $slug = 'activity-generate';

    protected static ?string $navigationLabel = 'Generate Activities';

    // Data

    private const string WINDOW_DAYS = 'days';

    private const string WINDOW_SLOT = 'slot';

    /** Relative-day windows run 08:00 to 17:00 local by default, so the value curve does not start at midnight. */
    private const string DAY_START = '08:00';

    private const string DAY_END = '17:00';

    private const string SLOT_START = '08:00';

    private const string SLOT_END = '12:00';

    protected function requiredForSubmit(): array
    {
        return ['activity_type_id', 'sla_type_id', 'base_value', 'duration', 'priority', 'latitude', 'longitude'];
    }

    public function activity_form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Activity Details')
                    ->icon('heroicon-s-arrow-path')
                    ->description('Scheduling: pick a day range (for example day 0 to day 7, 08:00 to 17:00) or a single appointment slot. Times are built in the time zone below.')
                    ->schema([
                        Fieldset::make('details')
                            ->label('Details')
                            ->schema([
                                $this->referenceSelect('activity_type_id', 'Activity Type ID', 'heroicon-o-tag', ReferenceEntityType::TaskType),
                                $this->referenceSelect('sla_type_id', 'SLA Type ID', 'heroicon-o-shield-check', ReferenceEntityType::SlaType),
                                TextInput::make('base_value')
                                    ->label(fn (Get $get): string => $get('base_value_per_hour') ? 'Base Value per Hour' : 'Base Value')
                                    ->required()
                                    ->prefixIcon('heroicon-o-calculator') // Numeric value/calculation
                                    ->minValue(1)
                                    ->default(3000)
                                    ->step(1)
                                    ->numeric()
                                    ->helperText(fn (): string => $this->baseValueHelperText())
                                    ->live(),
                                Toggle::make('base_value_per_hour')
                                    ->label('Base value is per hour of duration')
                                    ->helperText('On: the value sent is this rate times the duration in hours.')
                                    ->default(false)
                                    ->live(),
                                TextInput::make('duration')
                                    ->label('Duration')
                                    ->prefixIcon('heroicon-o-clock') // Time duration
                                    ->required()
                                    ->helperText('in minutes')
                                    ->minValue(10)
                                    ->default(60)
                                    ->step(1)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('priority')
                                    ->label('Activity Priority')
                                    ->helperText('Sent as the activity\'s own priority (Activity.priority), not the SLA row\'s.')
                                    ->minValue(1)
                                    ->prefixIcon('heroicon-o-exclamation-triangle') // Priority/importance
                                    ->required()
                                    ->default(1)
                                    ->step(1)
                                    ->numeric()
                                    ->live(),
                                Select::make('time_zone')
                                    ->label('Time Zone')
                                    ->prefixIcon('heroicon-o-globe-alt') // Globe for timezone
                                    ->searchable()
                                    ->options(static fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                                    ->placeholder(fn (): string => 'Dataset zone ('.$this->datasetTimeZone().')')
                                    ->helperText('Days and times below are in this zone, and are sent with its UTC offset, so daylight saving is handled. Blank means the dataset\'s time zone (UTC if it has none).')
                                    ->live(),

                            ])->columns(2),
                        Fieldset::make('scheduling')
                            ->label('Scheduling')
                            ->schema([
                                Radio::make('window_mode')
                                    ->label('SLA window')
                                    ->options([
                                        self::WINDOW_DAYS => 'Day range',
                                        self::WINDOW_SLOT => 'Appointment slot',
                                    ])
                                    ->descriptions([
                                        self::WINDOW_DAYS => 'From a time on the start day to a time on the end day.',
                                        self::WINDOW_SLOT => 'One slot on one day, as an appointment template would offer it.',
                                    ])
                                    ->default(self::WINDOW_DAYS)
                                    ->inline()
                                    ->live()
                                    ->columnSpanFull(),
                                TextInput::make('relative_day')
                                    ->label('Relative Day Start')
                                    ->helperText('0 is today in the time zone above.')
                                    ->prefixIcon('heroicon-o-calendar-days') // Calendar for day
                                    ->minValue(0)
                                    ->default(0)
                                    ->step(1)
                                    ->numeric()
                                    ->visible(fn (Get $get): bool => $this->windowMode($get('window_mode')) === self::WINDOW_DAYS)
                                    ->live(),
                                TextInput::make('relative_day_end')
                                    ->label('Relative Day End')
                                    ->prefixIcon('heroicon-o-calendar-days') // Calendar for day
                                    ->minValue(fn (Get $get): int => (int) $get('relative_day'))
                                    ->default(0)
                                    ->step(1)
                                    ->numeric()
                                    ->visible(fn (Get $get): bool => $this->windowMode($get('window_mode')) === self::WINDOW_DAYS)
                                    ->live(),
                                TimePicker::make('day_start_time')
                                    ->label('From (on the start day)')
                                    ->prefixIcon('heroicon-o-clock')
                                    ->seconds(false)
                                    ->default(self::DAY_START)
                                    ->visible(fn (Get $get): bool => $this->windowMode($get('window_mode')) === self::WINDOW_DAYS)
                                    ->live(),
                                TimePicker::make('day_end_time')
                                    ->label('To (on the end day)')
                                    ->prefixIcon('heroicon-o-clock')
                                    ->seconds(false)
                                    ->default(self::DAY_END)
                                    ->visible(fn (Get $get): bool => $this->windowMode($get('window_mode')) === self::WINDOW_DAYS)
                                    ->live(),
                                TextInput::make('slot_day')
                                    ->label('Slot Day')
                                    ->helperText('0 is today in the time zone above.')
                                    ->prefixIcon('heroicon-o-calendar-days')
                                    ->minValue(0)
                                    ->default(0)
                                    ->step(1)
                                    ->numeric()
                                    ->visible(fn (Get $get): bool => $this->windowMode($get('window_mode')) === self::WINDOW_SLOT)
                                    ->live(),
                                TimePicker::make('slot_start')
                                    ->label('Slot Start')
                                    ->prefixIcon('heroicon-o-clock')
                                    ->seconds(false)
                                    ->default(self::SLOT_START)
                                    ->helperText('Type the slot times from the appointment template: its slots are not synced into this tool yet.')
                                    ->visible(fn (Get $get): bool => $this->windowMode($get('window_mode')) === self::WINDOW_SLOT)
                                    ->live(),
                                TimePicker::make('slot_end')
                                    ->label('Slot End')
                                    ->prefixIcon('heroicon-o-clock')
                                    ->seconds(false)
                                    ->default(self::SLOT_END)
                                    ->visible(fn (Get $get): bool => $this->windowMode($get('window_mode')) === self::WINDOW_SLOT)
                                    ->live(),
                            ])->columns(2),
                        Fieldset::make('location')
                            ->label('Location')
                            ->schema([
                                TextInput::make('latitude')
                                    ->prefixIcon('heroicon-s-arrows-up-down')
                                    ->required()
                                    ->minValue(-90.0)
                                    ->maxValue(90.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('longitude')
                                    ->prefixIcon('heroicon-s-arrows-right-left')
                                    ->required()
                                    ->minValue(-180.0)
                                    ->maxValue(180.0)
                                    ->numeric()
                                    ->live(),
                                TextInput::make('address')
                                    ->prefixIcon('heroicon-s-map')
                                    ->columnSpan(2)
                                    ->suffixAction(
                                        Action::make('geocode_address')
                                            ->icon('heroicon-m-map-pin')
                                            ->action(function (Get $get, Set $set) {
                                                GeocodeHelper::geocodeFormAddress($get, $set);

                                            }))
                                    ->hint('click the map icon to geocode this!'),
                            ]),
                        Fieldset::make('optional')
                            ->label('Optional')
                            ->schema([
                                TextInput::make('activity_id')
                                    ->prefixIcon('heroicon-o-hashtag') // ID field
                                    ->helperText('will be a UUID if not included'),
                                TextInput::make('description')
                                    ->prefixIcon('heroicon-o-document-text')
                                    ->helperText(fn (): string => 'Shown on the Gantt. Blank sends "'.$this->activityDescription().'".')
                                    ->live(onBlur: true),
                                $this->inputDatetimeField(),
                                Forms\Components\Repeater::make('skills')
                                    ->simple($this->referenceSelect('skill', 'Skill', 'heroicon-o-cog-6-tooth', ReferenceEntityType::Skill, required: false))
                                    ->addActionLabel('Add Skill')->defaultItems(0),
                                Forms\Components\Repeater::make('regions')
                                    ->simple($this->referenceSelect('region', 'Region', 'heroicon-o-map', ReferenceEntityType::Region, required: false))
                                    ->addActionLabel('Add Region')->defaultItems(0),
                            ])->columns(3),

                        Callout::make('Some values are not from a PSO sync')
                            ->description(fn (): string => $this->unverifiedReferencesWarning())
                            ->warning()
                            ->visible(fn (): bool => $this->unverifiedReferences() !== [])
                            ->columnSpanFull(),

                        Actions::make([Action::make('create_activity')
                            ->disabled(fn (): bool => ! $this->canSubmit())
                            ->requiresConfirmation(fn (): bool => $this->unverifiedReferences() !== [])
                            ->modalHeading('Send values PSO may not have?')
                            ->modalDescription(fn (): string => $this->unverifiedReferencesWarning())
                            ->modalSubmitActionLabel('Send anyway')
                            ->action(function () {
                                $this->createActivity();

                            }),
                        ])->columnSpanFull(),

                    ])->columns(1),

            ])->statePath('activity_data');
    }

    /** The dataset picked in the environment section, if any (the select holds its PSO name). */
    public function currentDataset(): ?Dataset
    {
        return $this->selectedEnvironment?->datasets->firstWhere('name', $this->selectedDataset);
    }

    /**
     * A searchable select over the current dataset's values of one type, keyed by PSO id,
     * with a create option that stores a manual row so the page stays usable before a sync.
     * Rows typed in here, or that the last sync did not see, are marked in the list and under the field.
     */
    private function referenceSelect(string $name, string $label, string $icon, ReferenceEntityType $type, bool $required = true): Select
    {
        return Select::make($name)
            ->label($label)
            ->prefixIcon($icon)
            ->searchable()
            ->required($required)
            ->live()
            ->options(fn (): array => $this->referenceOptions($type))
            ->hint(fn (?string $state): ?string => match (true) {
                ! $this->currentDataset() => 'Pick an environment and dataset above to see its values.',
                filled($state) && ($reason = $this->unverifiedReason($type, $state)) !== null => ucfirst($reason),
                default => null,
            })
            ->hintColor('warning')
            ->createOptionForm(fn (): array => DatasetReferenceValueResource::formSchema($type, lockDataset: true, datasetId: fn (): ?string => $this->currentDataset()?->id))
            ->createOptionUsing(function (array $data) use ($type): string {
                $dataset = $this->currentDataset();
                $this->referenceRowCache = [];

                return $dataset ? DatasetReferenceValue::createManual($dataset->id, $type, $data)->pso_id : $data['pso_id'];
            });
    }

    /** @var array<string, Collection<string, DatasetReferenceValue>> rows per dataset and type, for one request */
    private array $referenceRowCache = [];

    /** @return Collection<string, DatasetReferenceValue> the current dataset's rows of one type, keyed by PSO id */
    private function referenceRows(ReferenceEntityType $type): Collection
    {
        $dataset = $this->currentDataset();
        if (! $dataset) {
            return collect();
        }

        return $this->referenceRowCache[$dataset->id.'|'.$type->value] ??= DatasetReferenceValue::query()
            ->where('dataset_id', $dataset->id)->ofType($type)->orderBy('label')->get()
            ->each(static fn (DatasetReferenceValue $row) => $row->setRelation('dataset', $dataset))
            ->keyBy('pso_id');
    }

    /** [PSO ID => "label (PSO ID)"], with a note on rows PSO may not have. */
    private function referenceOptions(ReferenceEntityType $type): array
    {
        return $this->referenceRows($type)
            ->map(fn (DatasetReferenceValue $row): string => "{$row->label} ({$row->pso_id})".(($reason = $this->rowUnverifiedReason($row)) !== null ? " [{$reason}]" : ''))
            ->all();
    }

    private function rowUnverifiedReason(DatasetReferenceValue $row): ?string
    {
        return match (true) {
            $row->origin === ReferenceValueOrigin::Manual => 'typed in, not from a PSO sync',
            $row->isMissingFromPso() => 'not seen in the last PSO sync',
            default => null,
        };
    }

    /** Why PSO may not have this value, or null when the last sync saw it. */
    private function unverifiedReason(ReferenceEntityType $type, string $psoId): ?string
    {
        $row = $this->referenceRows($type)->get($psoId);

        return $row ? $this->rowUnverifiedReason($row) : 'not in this dataset\'s values';
    }

    /**
     * The chosen activity type, SLA type, skills and regions that PSO may not have loaded: sending one
     * breaks the Activity_Type reference or makes an activity no resource can do, which looks like a DSE fault.
     *
     * @return list<string> lines such as "Skill FIBRE: typed in, not from a PSO sync"
     */
    public function unverifiedReferences(): array
    {
        if (! $this->currentDataset()) {
            return [];
        }

        $chosen = [
            [ReferenceEntityType::TaskType, [$this->activity_data['activity_type_id'] ?? null]],
            [ReferenceEntityType::SlaType, [$this->activity_data['sla_type_id'] ?? null]],
            [ReferenceEntityType::Skill, $this->simpleRepeaterValues('skills', 'skill')],
            [ReferenceEntityType::Region, $this->simpleRepeaterValues('regions', 'region')],
        ];

        $lines = [];
        foreach ($chosen as [$type, $values]) {
            foreach (array_filter($values, static fn (mixed $value): bool => filled($value)) as $value) {
                if (($reason = $this->unverifiedReason($type, (string) $value)) !== null) {
                    $lines[] = "{$type->getLabel()} {$value}: {$reason}";
                }
            }
        }

        return $lines;
    }

    public function unverifiedReferencesWarning(): string
    {
        return implode('. ', $this->unverifiedReferences()).'. If PSO does not have a value, the activity is rejected or no resource can do it, which looks like a scheduling fault. Sync the dataset\'s values to check.';
    }

    /**
     * @throws JsonException
     */
    public function createActivity(): void
    {
        $this->response = null;
        $this->validateForms($this->getForms());

        $payload = $this->generateActivitiesPayload();

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {
            $this->response = $this->sendToPSONew('activity', $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }
    }

    private function generateActivitiesPayload(): array
    {
        $skills = $this->simpleRepeaterValues('skills', 'skill');
        $regions = $this->simpleRepeaterValues('regions', 'region');
        $slaWindow = $this->calculateSlaWindow();

        return $this->buildPayload(
            required: [
                'activityTypeId' => $this->activity_data['activity_type_id'],
                'slaTypeId' => $this->activity_data['sla_type_id'],
                'slaStart' => $slaWindow['slaStart'],
                'slaEnd' => $slaWindow['slaEnd'],
                'baseValue' => $this->baseValue(),
                'duration' => $this->activity_data['duration'],
                'priority' => $this->activity_data['priority'],
                'lat' => $this->activity_data['latitude'],
                'long' => $this->activity_data['longitude'],
                'description' => filled($this->activity_data['description'] ?? null) ? $this->activity_data['description'] : $this->activityDescription(),
            ],
            optional: [
                'activityId' => $this->activity_data['activity_id'] ?? null,
                'skills' => $skills ?: null,
                'regions' => $regions ?: null,
                'inputDatetime' => $this->inputDatetimePayloadValue($this->activity_data['input_datetime'] ?? null),
            ],
        );
    }

    /**
     * The filled values of a simple repeater as a flat list. Its live state holds one
     * [uuid => [field => value]] row per item (pluck() on the old flat assumption gave nulls,
     * and a flat read gave nested arrays); a flat list, as tests set it, is read as it is.
     *
     * @return list<string>
     */
    private function simpleRepeaterValues(string $repeater, string $field): array
    {
        return collect($this->activity_data[$repeater] ?? [])
            ->map(static fn (mixed $item): mixed => is_array($item) ? ($item[$field] ?? null) : $item)
            ->filter(static fn (mixed $value): bool => filled($value))
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /** The base value sent: the field as it is, or the per-hour rate times the duration in hours. */
    private function baseValue(): int|float|string|null
    {
        $value = $this->activity_data['base_value'] ?? null;
        if (! ($this->activity_data['base_value_per_hour'] ?? false) || ! is_numeric($value)) {
            return $value;
        }

        return round((float) $value * ((float) ($this->activity_data['duration'] ?? 0)) / 60, 2);
    }

    private function baseValueHelperText(): string
    {
        $note = 'Must be above 0: PSO never schedules an activity worth 0.';

        return ($this->activity_data['base_value_per_hour'] ?? false) && is_numeric($this->activity_data['base_value'] ?? null)
            ? $note.' Sends '.$this->baseValue().' for this duration.'
            : $note;
    }

    /** "{type} : {address}", or the coordinates when there is no address, so the Gantt shows something. */
    private function activityDescription(): string
    {
        $type = filled($this->activity_data['activity_type_id'] ?? null) ? $this->activity_data['activity_type_id'] : 'Activity';
        $where = filled($this->activity_data['address'] ?? null)
            ? $this->activity_data['address']
            : trim(($this->activity_data['latitude'] ?? '').', '.($this->activity_data['longitude'] ?? ''), ', ');

        return filled($where) ? "{$type} : {$where}" : (string) $type;
    }

    /** The zone used when the page's time zone is blank: the dataset's own (UTC when it has none). */
    private function datasetTimeZone(): string
    {
        return $this->currentDataset()?->timeZone() ?? 'UTC';
    }

    private function activityTimeZone(): DateTimeZone
    {
        $zone = $this->activity_data['time_zone'] ?? null;

        return new DateTimeZone(filled($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : $this->datasetTimeZone());
    }

    private function windowMode(?string $mode): string
    {
        return $mode === self::WINDOW_SLOT ? self::WINDOW_SLOT : self::WINDOW_DAYS;
    }

    /**
     * The SLA window as Atom strings with the zone's offset on that date, so a window that
     * crosses a daylight saving change keeps its local times.
     *
     * - Day range: day_start_time (08:00) on relative_day to day_end_time (17:00) on relative_day_end.
     * - Slot: slot_start to slot_end on slot_day.
     *
     * Days count from today in the activity's zone, or from the input date time when one is set.
     *
     * @throws ValidationException when the window ends at or before its start
     */
    private function calculateSlaWindow(): array
    {
        $zone = $this->activityTimeZone();
        $input = $this->activity_data['input_datetime'] ?? null;
        $today = (filled($input) ? CarbonImmutable::parse($input) : CarbonImmutable::now())->setTimezone($zone)->startOfDay();
        $data = $this->activity_data;

        if ($this->windowMode($data['window_mode'] ?? null) === self::WINDOW_SLOT) {
            $day = $today->addDays((int) ($data['slot_day'] ?? 0));
            $slaStart = self::atLocalTime($day, $data['slot_start'] ?? null, self::SLOT_START);
            $slaEnd = self::atLocalTime($day, $data['slot_end'] ?? null, self::SLOT_END);
            $endField = 'slot_end';
        } else {
            $startDay = (int) ($data['relative_day'] ?? 0);
            $endDay = filled($data['relative_day_end'] ?? null) ? (int) $data['relative_day_end'] : $startDay;
            $slaStart = self::atLocalTime($today->addDays($startDay), $data['day_start_time'] ?? null, self::DAY_START);
            $slaEnd = self::atLocalTime($today->addDays($endDay), $data['day_end_time'] ?? null, self::DAY_END);
            $endField = 'day_end_time';
        }

        if ($slaEnd <= $slaStart) {
            throw ValidationException::withMessages([
                "activity_data.{$endField}" => 'The SLA window must end after it starts.',
            ]);
        }

        return [
            'slaStart' => $slaStart->toAtomString(),
            'slaEnd' => $slaEnd->toAtomString(),
        ];
    }

    /** A wall-clock "HH:MM" (or "HH:MM:SS") on the given local day; blank uses the default. */
    private static function atLocalTime(CarbonImmutable $day, ?string $clock, string $default): CarbonImmutable
    {
        $clock = filled($clock) ? $clock : $default;
        [$hour, $minute] = array_map('intval', array_pad(explode(':', $clock), 2, 0));

        return $day->setTime($hour, $minute);
    }
}
