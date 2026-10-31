<?php

namespace App\Support\DatasetBuilder\Spec;

use App\Support\DatasetBuilder\Decimal;
use App\Support\DatasetBuilder\InvalidDatasetSpec;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

/**
 * A Dataset Builder seed spec (resources/datasets/{id}.json), validated. Stage 1
 * reads regions, skills, resource types, resources and the commit rule set;
 * stage 1R adds `modelling` (also write the .arp) and each type's
 * `shift_category_colour`; stage 1R2 the activity reference data:
 * `sla_types`, `activity_types`, `appointment_templates` (template kinds,
 * made per region from the regions' `appointment_templates`); stage 2 the
 * `stage2` block (Stage2Spec), the first activities; stage 3 each activity
 * type's `work_mode` and `primary_sla_type`, `extra_region_weight` and the
 * `stage3` block (Stage3Spec), booked appointments; stage 4 the `stage4`
 * block (Stage4Spec), splittable PROJECT work. Everything else
 * in the file (`_later_stages`, `activity_cap`) is kept in the JSON for later
 * stages and ignored here. Reference data values are kept as the file text
 * they become (numbers without trailing zeros, booleans as true/false).
 */
readonly class DatasetSpec
{
    public const int MAX_ID_LENGTH = 32;

    /** What a resource's `overrides` may set, and how each is checked; in the Resources field order of the cheat sheet. */
    public const array OVERRIDABLE = [
        'travel_to' => 'duration',
        'travel_from' => 'duration',
        'cost_ph' => 'number',
        'cost_phot' => 'number',
        'cost_km' => 'number',
        'utilisation' => 'number',
    ];

    /** The stage 1R cheat sheet's planner colours (ARGB), for a type with no `shift_category_colour`. */
    public const array SHIFT_CATEGORY_COLOURS = [
        'FIELD_TECH' => '#FF0087C1',
        'SENIOR_TECH' => '#FF6A1B9A',
        'INSTALLER' => '#FF2E7D32',
        'CONTRACTOR' => '#FFEF6C00',
    ];

    /**
     * @param  array{duration: string, appointment_window_duration: string, process_type: string}  $inputReference
     * @param  array{id: string, parameters: array<string, string>}  $ruleCollection
     * @param  array<string, RegionSpec>  $regions  by id, in spec order
     * @param  array<string, string>  $skills  id => description
     * @param  array<string, ResourceTypeSpec>  $resourceTypes  by id, in spec order
     * @param  list<ResourceSpec>  $resources
     * @param  array<string, array<string, string>>  $slaTypes  id => description, mechanism_type, curve and value fields
     * @param  array<string, array<string, string>>  $activityTypes  id => description, duration, base_value, split_allowed, min_split, cost_of_split, priority, work_mode, primary_sla_type
     * @param  array<string, array{description: string, day_pattern: string, appointment_window_length: string, items: list<array{start: string, end: string}>}>  $appointmentTemplateKinds
     */
    public function __construct(
        public string $datasetId,
        public string $organisationId,
        public string $description,
        public int $seed,
        public string $baseTimeZone,
        public int $horizonDays,
        public array $inputReference,
        public array $ruleCollection,
        public array $regions,
        public array $skills,
        public array $resourceTypes,
        public array $resources,
        public bool $modelling,
        public array $slaTypes = [],
        public array $activityTypes = [],
        public array $appointmentTemplateKinds = [],
        public ?Stage2Spec $stage2 = null,
        public float $extraRegionWeight = 0.5,
        public ?Stage3Spec $stage3 = null,
        public ?Stage4Spec $stage4 = null,
    ) {}

    /**
     * A region's serving resources: its home resources, plus `extra_region_weight`
     * for each resource that also covers it through `extra_regions`.
     */
    public function servingResources(string $regionId): float
    {
        $serving = 0.0;

        foreach ($this->resources as $resource) {
            if ($resource->region === $regionId) {
                $serving += 1;
            } elseif (in_array($regionId, $resource->extraRegions, true)) {
                $serving += $this->extraRegionWeight;
            }
        }

        return $serving;
    }

    /** @return list<ResourceSpec> the region's home resources, then those covering it through `extra_regions` */
    public function servingResourceList(string $regionId): array
    {
        return [
            ...array_filter($this->resources, static fn (ResourceSpec $resource): bool => $resource->region === $regionId),
            ...array_filter($this->resources, static fn (ResourceSpec $resource): bool => in_array($regionId, $resource->extraRegions, true)),
        ];
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidDatasetSpec("No seed spec at {$path}.");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidDatasetSpec(basename($path).' is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($data)) {
            throw new InvalidDatasetSpec(basename($path).' does not hold a JSON object.');
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $datasetId = self::id($data['dataset_id'] ?? null, 'dataset_id');

        $inputReference = [
            'duration' => self::duration($data['input_reference']['duration'] ?? null, 'input_reference.duration'),
            'appointment_window_duration' => self::duration($data['input_reference']['appointment_window_duration'] ?? null, 'input_reference.appointment_window_duration'),
            'process_type' => self::text($data['input_reference']['process_type'] ?? null, 'input_reference.process_type'),
        ];

        $horizonDays = $data['horizon_days'] ?? null;

        if (! is_int($horizonDays) || $horizonDays < 1) {
            throw new InvalidDatasetSpec('horizon_days must be a whole number of days, 1 or more.');
        }

        $windowDays = self::days($inputReference['duration']) + self::days($inputReference['appointment_window_duration']);

        if ($horizonDays < $windowDays) {
            throw new InvalidDatasetSpec("horizon_days is {$horizonDays}, but the DSE window plus the appointment window is {$windowDays} days: shifts must cover both.");
        }

        $skills = [];

        foreach (self::list($data['skills'] ?? null, 'skills') as $index => $skill) {
            $id = self::id($skill['id'] ?? null, "skills[{$index}].id");
            $skills[$id] = self::text($skill['description'] ?? null, "skills[{$index}].description");
        }

        $slaTypes = [];

        foreach ((array) ($data['sla_types'] ?? []) as $id => $type) {
            $slaTypes[self::id((string) $id, 'sla_types key')] = self::slaType((string) $id, (array) $type);
        }

        $activityTypes = [];

        foreach ((array) ($data['activity_types'] ?? []) as $id => $type) {
            $activityTypes[self::id((string) $id, 'activity_types key')] = self::activityType((string) $id, (array) $type, $slaTypes);
        }

        $templateKinds = [];

        foreach ((array) ($data['appointment_templates'] ?? []) as $kind => $template) {
            $templateKinds[(string) $kind] = self::templateKind((string) $kind, (array) $template);
        }

        $regions = [];

        foreach (self::list($data['regions'] ?? null, 'regions') as $index => $region) {
            $region = self::region($region, "regions[{$index}]");

            foreach ($region->appointmentTemplates as $kind) {
                if (! isset($templateKinds[$kind])) {
                    throw new InvalidDatasetSpec("Region {$region->id}: appointment template {$kind} is not in appointment_templates.");
                }

                self::id("{$region->id}-{$kind}", "Region {$region->id} appointment template id");
            }

            $regions[$region->id] = $region;
        }

        $resourceTypes = [];

        foreach ((array) ($data['resource_types'] ?? []) as $id => $type) {
            $resourceTypes[(string) $id] = self::resourceType((string) $id, (array) $type);
        }

        if ($resourceTypes === []) {
            throw new InvalidDatasetSpec('resource_types is missing or empty.');
        }

        $resources = [];
        $seen = [];

        foreach (self::list($data['resources'] ?? null, 'resources') as $index => $resource) {
            $resource = self::resource($index + 1, (array) $resource, $regions, $skills, $resourceTypes);

            if (isset($seen[$resource->id])) {
                throw new InvalidDatasetSpec("Resource {$resource->id} appears twice.");
            }

            $seen[$resource->id] = true;
            $resources[] = $resource;
        }

        if (count($resources) > 999) {
            throw new InvalidDatasetSpec('At most 999 resources: shift ids carry the resource number in three digits.');
        }

        $ruleCollection = (array) ($data['rule_collection'] ?? []);
        $ruleId = self::id($ruleCollection['id'] ?? null, 'rule_collection.id');
        unset($ruleCollection['id']);

        $parameters = [];

        foreach ($ruleCollection as $name => $value) {
            $parameters[(string) $name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $modelling = $data['modelling'] ?? true;

        if (! is_bool($modelling)) {
            throw new InvalidDatasetSpec('modelling must be true or false.');
        }

        $extraRegionWeight = self::number($data['extra_region_weight'] ?? 0.5, 'extra_region_weight');

        if ($extraRegionWeight < 0) {
            throw new InvalidDatasetSpec('extra_region_weight must be 0 or more.');
        }

        $seed = $data['seed'] ?? null;

        if (! is_int($seed)) {
            throw new InvalidDatasetSpec('seed must be a whole number.');
        }

        return new self(
            datasetId: $datasetId,
            organisationId: self::text($data['organisation_id'] ?? null, 'organisation_id'),
            description: self::text($data['description'] ?? null, 'description'),
            seed: $seed,
            baseTimeZone: self::timeZone($data['base_time_zone'] ?? null, 'base_time_zone'),
            horizonDays: $horizonDays,
            inputReference: $inputReference,
            ruleCollection: ['id' => $ruleId, 'parameters' => $parameters],
            regions: $regions,
            skills: $skills,
            resourceTypes: $resourceTypes,
            resources: $resources,
            modelling: $modelling,
            slaTypes: $slaTypes,
            activityTypes: $activityTypes,
            appointmentTemplateKinds: $templateKinds,
            stage2: isset($data['stage2']) ? self::stage2((array) $data['stage2'], $regions, $skills, $resourceTypes, $activityTypes, $slaTypes) : null,
            extraRegionWeight: $extraRegionWeight,
            stage3: isset($data['stage3']) ? self::stage3((array) $data['stage3'], $regions, $skills, $activityTypes, $templateKinds) : null,
            stage4: isset($data['stage4']) ? self::stage4((array) $data['stage4'], $skills, $activityTypes, $slaTypes) : null,
        );
    }

    public function withSeed(int $seed): self
    {
        return new self(
            $this->datasetId, $this->organisationId, $this->description, $seed, $this->baseTimeZone, $this->horizonDays,
            $this->inputReference, $this->ruleCollection, $this->regions, $this->skills, $this->resourceTypes, $this->resources,
            $this->modelling, $this->slaTypes, $this->activityTypes, $this->appointmentTemplateKinds, $this->stage2,
            $this->extraRegionWeight, $this->stage3, $this->stage4,
        );
    }

    /** @param array<string, mixed> $region */
    private static function region(mixed $region, string $path): RegionSpec
    {
        $region = (array) $region;
        $id = self::id($region['id'] ?? null, "{$path}.id");
        $kind = $region['kind'] ?? null;

        if (! in_array($kind, ['metro', 'rural'], true)) {
            throw new InvalidDatasetSpec("Region {$id}: kind must be metro or rural.");
        }

        $centroids = [];

        foreach (self::list($region['centroids'] ?? null, "Region {$id} centroids") as $index => $centroid) {
            $centroids[] = [
                'label' => self::text($centroid['label'] ?? null, "Region {$id} centroids[{$index}].label"),
                'lat' => self::number($centroid['lat'] ?? null, "Region {$id} centroids[{$index}].lat"),
                'lon' => self::number($centroid['lon'] ?? null, "Region {$id} centroids[{$index}].lon"),
                'weight' => max(1, (int) ($centroid['weight'] ?? 1)),
            ];
        }

        if ($kind === 'rural' && count($centroids) < 2) {
            throw new InvalidDatasetSpec("Region {$id} is rural, so it needs at least two centroids to lay homes along.");
        }

        $depot = $region['depot'] ?? null;

        if ($depot !== null) {
            $depot = [
                'lat' => self::number($depot['lat'] ?? null, "Region {$id} depot.lat"),
                'lon' => self::number($depot['lon'] ?? null, "Region {$id} depot.lon"),
                'address_line1' => self::text($depot['address_line1'] ?? null, "Region {$id} depot.address_line1"),
                'city' => self::text($depot['city'] ?? null, "Region {$id} depot.city"),
                'postcode' => self::text($depot['postcode'] ?? null, "Region {$id} depot.postcode"),
            ];
        }

        $streetNames = array_values(array_map('strval', (array) ($region['street_names'] ?? [])));

        if ($streetNames === []) {
            throw new InvalidDatasetSpec("Region {$id} needs street_names for its addresses.");
        }

        $excludePolygons = [];

        foreach ((array) ($region['exclude_polygons'] ?? []) as $index => $ring) {
            $points = [];

            foreach ((array) $ring as $point) {
                if (! is_array($point) || count($point) !== 2) {
                    throw new InvalidDatasetSpec("Region {$id} exclude_polygons[{$index}]: each point must be [lat, lon].");
                }

                $points[] = [self::number($point[0], "Region {$id} exclude_polygons[{$index}] lat"), self::number($point[1], "Region {$id} exclude_polygons[{$index}] lon")];
            }

            if (count($points) < 3) {
                throw new InvalidDatasetSpec("Region {$id} exclude_polygons[{$index}] needs at least three points.");
            }

            $excludePolygons[] = $points;
        }

        return new RegionSpec(
            id: $id,
            description: self::text($region['description'] ?? null, "Region {$id} description"),
            kind: $kind,
            timeZone: self::timeZone($region['time_zone'] ?? null, "Region {$id} time_zone"),
            state: self::text($region['state'] ?? null, "Region {$id} state"),
            postcodePrefix: self::text($region['postcode_prefix'] ?? null, "Region {$id} postcode_prefix"),
            centroids: $centroids,
            spreadKm: self::number($region['spread_km'] ?? null, "Region {$id} spread_km"),
            depot: $depot,
            streetNames: $streetNames,
            excludePolygons: $excludePolygons,
            appointmentTemplates: array_values(array_map('strval', (array) ($region['appointment_templates'] ?? []))),
        );
    }

    /** @param array<string, mixed> $type */
    private static function resourceType(string $id, array $type): ResourceTypeSpec
    {
        self::id($id, 'resource_types key');
        $days = array_values((array) ($type['shift']['days'] ?? []));
        // RAM_Shift_Template_Item_Break id: {type}-{sequence}.
        self::id("{$id}-".max(1, count($days)), "Resource type {$id} shift template break id");

        if ($days === [] || array_filter($days, static fn (mixed $day): bool => ! is_int($day) || $day < 1 || $day > 7) !== []) {
            throw new InvalidDatasetSpec("Resource type {$id}: shift.days must list ISO weekdays, 1 (Monday) to 7 (Sunday).");
        }

        $start = self::clock($type['shift']['start'] ?? null, "Resource type {$id} shift.start");
        $end = self::clock($type['shift']['end'] ?? null, "Resource type {$id} shift.end");

        if ($end <= $start) {
            throw new InvalidDatasetSpec("Resource type {$id}: the shift must end after it starts on the same day.");
        }

        $startEnd = $type['start_end'] ?? null;

        if (! in_array($startEnd, ['home', 'depot'], true)) {
            throw new InvalidDatasetSpec("Resource type {$id}: start_end must be home or depot.");
        }

        $colour = $type['shift_category_colour'] ?? self::SHIFT_CATEGORY_COLOURS[$id] ?? null;

        if (! is_string($colour) || preg_match('/\A#[0-9A-Fa-f]{8}\z/', $colour) !== 1) {
            throw new InvalidDatasetSpec("Resource type {$id}: shift_category_colour must be an ARGB colour such as #FF0087C1.");
        }

        return new ResourceTypeSpec(
            id: $id,
            description: self::text($type['description'] ?? null, "Resource type {$id} description"),
            shiftDays: $days,
            shiftStart: $start,
            shiftEnd: $end,
            breakDuration: self::duration($type['break']['duration'] ?? null, "Resource type {$id} break.duration"),
            breakEarliestOffset: self::duration($type['break']['earliest_offset'] ?? null, "Resource type {$id} break.earliest_offset"),
            breakLatestOffset: self::duration($type['break']['latest_offset'] ?? null, "Resource type {$id} break.latest_offset"),
            startEnd: $startEnd,
            travelTo: self::duration($type['travel_to'] ?? null, "Resource type {$id} travel_to"),
            travelFrom: self::duration($type['travel_from'] ?? null, "Resource type {$id} travel_from"),
            maxTravel: self::duration($type['max_travel'] ?? null, "Resource type {$id} max_travel"),
            costPh: self::number($type['cost_ph'] ?? null, "Resource type {$id} cost_ph"),
            costPhot: self::number($type['cost_phot'] ?? null, "Resource type {$id} cost_phot"),
            costKm: self::number($type['cost_km'] ?? null, "Resource type {$id} cost_km"),
            utilisation: (int) self::number($type['utilisation'] ?? null, "Resource type {$id} utilisation"),
            shiftCategoryColour: strtoupper($colour),
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, RegionSpec>  $regions
     * @param  array<string, string>  $skills
     * @param  array<string, ResourceTypeSpec>  $resourceTypes
     */
    private static function resource(int $sequence, array $resource, array $regions, array $skills, array $resourceTypes): ResourceSpec
    {
        $id = self::id($resource['id'] ?? null, "resources[{$sequence}].id");
        $region = (string) ($resource['region'] ?? '');
        $type = (string) ($resource['resource_type'] ?? '');

        if (! isset($regions[$region])) {
            throw new InvalidDatasetSpec("Resource {$id}: region {$region} is not in regions.");
        }

        if (! isset($resourceTypes[$type])) {
            throw new InvalidDatasetSpec("Resource {$id}: resource_type {$type} is not in resource_types.");
        }

        if ($resourceTypes[$type]->startsAtDepot() && $regions[$region]->depot === null) {
            throw new InvalidDatasetSpec("Resource {$id} is a {$type}, which starts at a depot, but region {$region} has no depot.");
        }

        $resourceSkills = array_values(array_map('strval', (array) ($resource['skills'] ?? [])));

        foreach ($resourceSkills as $skill) {
            if (! isset($skills[$skill])) {
                throw new InvalidDatasetSpec("Resource {$id}: skill {$skill} is not in skills.");
            }
        }

        $extraRegions = array_values(array_map('strval', (array) ($resource['extra_regions'] ?? [])));

        foreach ($extraRegions as $extra) {
            if (! isset($regions[$extra]) || $extra === $region) {
                throw new InvalidDatasetSpec("Resource {$id}: extra region {$extra} must be another region in regions.");
            }

            // Availability ids: AV-{resource}-{region}, RRA-{resource}-{region}.
            self::id("RRA-{$id}-{$extra}", "Resource {$id} extra region {$extra} availability id");
        }

        self::id("H-{$id}", "Resource {$id} home location id");
        self::id("RMP-{$id}", "Resource {$id} rota membership period id");

        foreach (array_keys(self::OVERRIDABLE) as $field) {
            if (array_key_exists($field, $resource)) {
                throw new InvalidDatasetSpec("Resource {$id}: {$field} comes from its resource type; put an exception under overrides.{$field}.");
            }
        }

        $overrides = [];
        $given = (array) ($resource['overrides'] ?? []);

        foreach (array_keys($given) as $field) {
            if (! isset(self::OVERRIDABLE[$field])) {
                throw new InvalidDatasetSpec("Resource {$id}: overrides.{$field} is not one of ".implode(', ', array_keys(self::OVERRIDABLE)).'.');
            }
        }

        foreach (self::OVERRIDABLE as $field => $kind) {
            if (array_key_exists($field, $given)) {
                $overrides[$field] = $kind === 'duration'
                    ? self::duration($given[$field], "Resource {$id} overrides.{$field}")
                    : Decimal::format(self::number($given[$field], "Resource {$id} overrides.{$field}"));
            }
        }

        return new ResourceSpec(
            sequence: $sequence,
            id: $id,
            firstName: self::text($resource['first_name'] ?? null, "Resource {$id} first_name"),
            surname: self::text($resource['surname'] ?? null, "Resource {$id} surname"),
            region: $region,
            resourceType: $type,
            skills: $resourceSkills,
            extraRegions: $extraRegions,
            overrides: $overrides,
        );
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, string>
     */
    private static function slaType(string $id, array $type): array
    {
        $path = "SLA type {$id}";
        $fields = ['description' => self::text($type['description'] ?? null, "{$path} description")];

        foreach (['revenue', 'penalty', 'cost', 'early_sla_benefit', 'start_proportion', 'end_proportion', 'curve_shape', 'activity_ageing_factor'] as $field) {
            $fields[$field] = Decimal::format(self::number($type[$field] ?? null, "{$path} {$field}"));
        }

        $fields['generate_jeopardy_exceptions'] = self::boolean($type['generate_jeopardy_exceptions'] ?? null, "{$path} generate_jeopardy_exceptions");
        $fields['mechanism_type'] = self::id($type['mechanism_type'] ?? null, "{$path} mechanism_type");

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $type
     * @param  array<string, array<string, string>>  $slaTypes
     * @return array<string, string>
     */
    private static function activityType(string $id, array $type, array $slaTypes): array
    {
        $path = "Activity type {$id}";
        $workMode = $type['work_mode'] ?? null;
        $primary = $type['primary_sla_type'] ?? null;

        if (! in_array($workMode, ['sla', 'appointment'], true)) {
            throw new InvalidDatasetSpec("{$path}: work_mode must be sla or appointment.");
        }

        if (! is_string($primary) || ! isset($slaTypes[$primary])) {
            throw new InvalidDatasetSpec("{$path}: primary_sla_type must be one of the sla_types.");
        }

        return [
            'description' => self::text($type['description'] ?? null, "{$path} description"),
            'duration' => self::duration($type['duration'] ?? null, "{$path} duration"),
            'base_value' => Decimal::format(self::number($type['base_value'] ?? null, "{$path} base_value")),
            'split_allowed' => self::boolean($type['split_allowed'] ?? null, "{$path} split_allowed"),
            'min_split' => self::duration($type['min_split'] ?? null, "{$path} min_split"),
            'cost_of_split' => Decimal::format(self::number($type['cost_of_split'] ?? null, "{$path} cost_of_split")),
            'priority' => Decimal::format(self::number($type['priority'] ?? null, "{$path} priority")),
            'work_mode' => $workMode,
            'primary_sla_type' => $primary,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array{description: string, day_pattern: string, appointment_window_length: string, items: list<array{start: string, end: string}>}
     */
    private static function templateKind(string $kind, array $template): array
    {
        $path = "Appointment template {$kind}";
        $dayPattern = $template['day_pattern'] ?? null;

        if (! is_string($dayPattern) || preg_match('/\A[YN]{7}\z/', $dayPattern) !== 1 || ! str_contains($dayPattern, 'Y')) {
            throw new InvalidDatasetSpec("{$path}: day_pattern must be seven Y or N, Monday first, with at least one Y.");
        }

        $items = [];

        foreach (self::list($template['items'] ?? null, "{$path} items") as $index => $item) {
            $start = self::duration($item['start'] ?? null, "{$path} items[{$index}].start");
            $end = self::duration($item['end'] ?? null, "{$path} items[{$index}].end");

            if (self::seconds($end) <= self::seconds($start)) {
                throw new InvalidDatasetSpec("{$path} items[{$index}]: the slot must end after it starts.");
            }

            $items[] = ['start' => $start, 'end' => $end];
        }

        return [
            'description' => self::text($template['description'] ?? null, "{$path} description"),
            'day_pattern' => $dayPattern,
            'appointment_window_length' => self::duration($template['appointment_window_length'] ?? null, "{$path} appointment_window_length"),
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, RegionSpec>  $regions
     * @param  array<string, string>  $skills
     * @param  array<string, ResourceTypeSpec>  $resourceTypes
     * @param  array<string, array<string, string>>  $activityTypes
     * @param  array<string, array<string, string>>  $slaTypes
     */
    private static function stage2(array $data, array $regions, array $skills, array $resourceTypes, array $activityTypes, array $slaTypes): Stage2Spec
    {
        $jobs = self::number($data['jobs_per_resource_per_day'] ?? null, 'stage2.jobs_per_resource_per_day');
        $days = $data['working_days'] ?? null;

        if ($jobs < 0) {
            throw new InvalidDatasetSpec('stage2.jobs_per_resource_per_day must be 0 or more.');
        }

        if (! is_int($days) || $days < 1 || $days > 20) {
            throw new InvalidDatasetSpec('stage2.working_days must be a whole number from 1 to 20.');
        }

        if (! isset($resourceTypes[Stage2Spec::WORKING_DAY_TYPE])) {
            throw new InvalidDatasetSpec('stage2 needs a '.Stage2Spec::WORKING_DAY_TYPE.' resource type: its shift end decides which days are still working days.');
        }

        foreach (['IN_SLA', 'OUT_SLA'] as $slaType) {
            if (! isset($slaTypes[$slaType])) {
                throw new InvalidDatasetSpec("stage2 needs the {$slaType} SLA type in sla_types.");
            }
        }

        $mix = [];
        $durations = [];
        $windows = [];
        $pools = [];

        foreach ((array) ($data['mix'] ?? []) as $type => $share) {
            $type = (string) $type;
            $path = "stage2 {$type}";

            if (! isset($activityTypes[$type])) {
                throw new InvalidDatasetSpec("stage2.mix: activity type {$type} is not in activity_types.");
            }

            if ($activityTypes[$type]['work_mode'] !== 'sla') {
                throw new InvalidDatasetSpec("stage2.mix: activity type {$type} is appointment work; stage 2 is SLA work only.");
            }

            $mix[$type] = self::number($share, "stage2.mix.{$type}");

            if ($mix[$type] < 0) {
                throw new InvalidDatasetSpec("stage2.mix.{$type} must be 0 or more.");
            }

            $min = intdiv(self::seconds(self::duration($data['durations'][$type]['min'] ?? null, "{$path} durations.min")), 60);
            $max = intdiv(self::seconds(self::duration($data['durations'][$type]['max'] ?? null, "{$path} durations.max")), 60);

            if ($min < 1 || $max < $min) {
                throw new InvalidDatasetSpec("{$path}: durations.min must be at least a minute and no more than durations.max.");
            }

            $durations[$type] = ['min' => $min, 'max' => $max];

            $in = $data['sla_windows'][$type]['in'] ?? null;
            $windows[$type] = [
                'in' => $in === Stage2Spec::SAME_DAY ? $in : self::duration($in, "{$path} sla_windows.in (or same_day)"),
                'out' => self::duration($data['sla_windows'][$type]['out'] ?? null, "{$path} sla_windows.out"),
            ];

            $pool = array_values(array_map('strval', (array) ($data['skills'][$type]['pool'] ?? [])));

            foreach ($pool as $skill) {
                if (! isset($skills[$skill])) {
                    throw new InvalidDatasetSpec("{$path} skills.pool: skill {$skill} is not in skills.");
                }
            }

            $p = self::number($data['skills'][$type]['p'] ?? null, "{$path} skills.p");

            if ($p < 0 || $p > 1) {
                throw new InvalidDatasetSpec("{$path} skills.p must be from 0 to 1.");
            }

            $pools[$type] = ['pool' => $pool, 'p' => $p];
        }

        if ($mix === [] || array_sum($mix) <= 0) {
            throw new InvalidDatasetSpec('stage2.mix must give at least one activity type a share above 0.');
        }

        foreach ($regions as $region) {
            // Ids: L-{region}-nnnnn and A-{region}-nnnnnn.
            self::id("A-{$region->id}-000000", "Region {$region->id} activity id");
        }

        return new Stage2Spec($jobs, $days, $mix, $durations, $windows, $pools);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, RegionSpec>  $regions
     * @param  array<string, string>  $skills
     * @param  array<string, array<string, string>>  $activityTypes
     * @param  array<string, array<string, mixed>>  $templateKinds
     */
    private static function stage3(array $data, array $regions, array $skills, array $activityTypes, array $templateKinds): Stage3Spec
    {
        $perDay = self::number($data['appointments_per_resource_per_day'] ?? null, 'stage3.appointments_per_resource_per_day');
        $days = $data['working_days'] ?? null;

        if ($perDay < 0) {
            throw new InvalidDatasetSpec('stage3.appointments_per_resource_per_day must be 0 or more.');
        }

        if (! is_int($days) || $days < 1 || $days > 20) {
            throw new InvalidDatasetSpec('stage3.working_days must be a whole number from 1 to 20.');
        }

        $mix = [];
        $durations = [];
        $pools = [];

        foreach ((array) ($data['mix'] ?? []) as $type => $share) {
            $type = (string) $type;
            $path = "stage3 {$type}";

            if (($activityTypes[$type]['work_mode'] ?? null) !== 'appointment') {
                throw new InvalidDatasetSpec("stage3.mix: {$type} must be an activity type whose work_mode is appointment.");
            }

            $mix[$type] = self::number($share, "stage3.mix.{$type}");

            if ($mix[$type] < 0) {
                throw new InvalidDatasetSpec("stage3.mix.{$type} must be 0 or more.");
            }

            $min = intdiv(self::seconds(self::duration($data['durations'][$type]['min'] ?? null, "{$path} durations.min")), 60);
            $max = intdiv(self::seconds(self::duration($data['durations'][$type]['max'] ?? null, "{$path} durations.max")), 60);

            if ($min < 1 || $max < $min) {
                throw new InvalidDatasetSpec("{$path}: durations.min must be at least a minute and no more than durations.max.");
            }

            $durations[$type] = ['min' => $min, 'max' => $max];
            $pool = array_values(array_map('strval', (array) ($data['skills'][$type]['pool'] ?? [])));

            foreach ($pool as $skill) {
                if (! isset($skills[$skill])) {
                    throw new InvalidDatasetSpec("{$path} skills.pool: skill {$skill} is not in skills.");
                }
            }

            $p = self::number($data['skills'][$type]['p'] ?? null, "{$path} skills.p");

            if ($p < 0 || $p > 1) {
                throw new InvalidDatasetSpec("{$path} skills.p must be from 0 to 1.");
            }

            $pools[$type] = ['pool' => $pool, 'p' => $p];
        }

        if ($mix === [] || array_sum($mix) <= 0) {
            throw new InvalidDatasetSpec('stage3.mix must give at least one activity type a share above 0.');
        }

        $weights = [];

        foreach ((array) ($data['kind_weights'] ?? []) as $kind => $weight) {
            if (! isset($templateKinds[$kind])) {
                throw new InvalidDatasetSpec("stage3.kind_weights: {$kind} is not in appointment_templates.");
            }

            $weights[(string) $kind] = self::number($weight, "stage3.kind_weights.{$kind}");

            if ($weights[(string) $kind] < 0) {
                throw new InvalidDatasetSpec("stage3.kind_weights.{$kind} must be 0 or more.");
            }
        }

        foreach ($regions as $region) {
            if (array_sum(array_map(static fn (string $kind): float => $weights[$kind] ?? 0, $region->appointmentTemplates)) <= 0) {
                throw new InvalidDatasetSpec("Region {$region->id}: none of its appointment templates has a stage3.kind_weights share above 0.");
            }

            // Availability id: AP-A-{region}-nnnnnn-1.
            self::id("AP-A-{$region->id}-000000-1", "Region {$region->id} appointment availability id");
        }

        $min = intdiv(self::seconds(self::duration($data['booked_ago']['min'] ?? null, 'stage3.booked_ago.min')), 60);
        $max = intdiv(self::seconds(self::duration($data['booked_ago']['max'] ?? null, 'stage3.booked_ago.max')), 60);

        if ($max < $min) {
            throw new InvalidDatasetSpec('stage3.booked_ago.min must be no more than booked_ago.max.');
        }

        return new Stage3Spec($perDay, $days, $mix, $durations, $pools, $weights, ['min' => $min, 'max' => $max]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $skills
     * @param  array<string, array<string, string>>  $activityTypes
     * @param  array<string, array<string, string>>  $slaTypes
     */
    private static function stage4(array $data, array $skills, array $activityTypes, array $slaTypes): Stage4Spec
    {
        if (! isset($activityTypes[Stage4Spec::TYPE])) {
            throw new InvalidDatasetSpec('stage4 needs the '.Stage4Spec::TYPE.' activity type in activity_types.');
        }

        if (! isset($slaTypes['OUT_SLA'])) {
            throw new InvalidDatasetSpec('stage4 needs the OUT_SLA SLA type in sla_types.');
        }

        $perWeek = [];

        foreach (['metro', 'rural'] as $kind) {
            $count = $data['projects_per_region_per_week'][$kind] ?? null;

            if (! is_int($count) || $count < 0) {
                throw new InvalidDatasetSpec("stage4.projects_per_region_per_week.{$kind} must be a whole number, 0 or more.");
            }

            $perWeek[$kind] = $count;
        }

        $weeks = $data['weeks'] ?? null;

        if (! is_int($weeks) || $weeks < 1 || $weeks > 8) {
            throw new InvalidDatasetSpec('stage4.weeks must be a whole number from 1 to 8.');
        }

        $min = intdiv(self::seconds(self::duration($data['duration']['min'] ?? null, 'stage4.duration.min')), 60);
        $max = intdiv(self::seconds(self::duration($data['duration']['max'] ?? null, 'stage4.duration.max')), 60);

        if ($min < 1 || $max < $min) {
            throw new InvalidDatasetSpec('stage4: duration.min must be at least a minute and no more than duration.max.');
        }

        $required = array_values(array_map('strval', (array) ($data['skills']['required'] ?? [])));
        $optional = [];

        foreach ($required as $skill) {
            if (! isset($skills[$skill])) {
                throw new InvalidDatasetSpec("stage4.skills.required: skill {$skill} is not in skills.");
            }
        }

        foreach ((array) ($data['skills']['optional'] ?? []) as $skill => $p) {
            if (! isset($skills[$skill])) {
                throw new InvalidDatasetSpec("stage4.skills.optional: skill {$skill} is not in skills.");
            }

            $optional[(string) $skill] = self::number($p, "stage4.skills.optional.{$skill}");

            if ($optional[(string) $skill] < 0 || $optional[(string) $skill] > 1) {
                throw new InvalidDatasetSpec("stage4.skills.optional.{$skill} must be from 0 to 1.");
            }
        }

        $weights = [];

        foreach ((array) ($data['start_day_weights'] ?? []) as $weekday => $weight) {
            if (! in_array((string) $weekday, ['1', '2', '3', '4', '5'], true)) {
                throw new InvalidDatasetSpec("stage4.start_day_weights: {$weekday} must be an ISO weekday from 1 (Monday) to 5 (Friday).");
            }

            $weights[(int) $weekday] = self::number($weight, "stage4.start_day_weights.{$weekday}");

            if ($weights[(int) $weekday] < 0) {
                throw new InvalidDatasetSpec("stage4.start_day_weights.{$weekday} must be 0 or more.");
            }
        }

        if (array_sum($weights) <= 0) {
            throw new InvalidDatasetSpec('stage4.start_day_weights must give at least one weekday a weight above 0.');
        }

        $priority = $data['split_minimum_priority'] ?? null;

        if (! is_int($priority) || $priority < 0) {
            throw new InvalidDatasetSpec('stage4.split_minimum_priority must be a whole number, 0 or more.');
        }

        $startBased = $data['sla']['start_based'] ?? null;

        if (! is_bool($startBased)) {
            throw new InvalidDatasetSpec('stage4.sla.start_based must be true or false.');
        }

        return new Stage4Spec(
            perRegionPerWeek: $perWeek,
            weeks: $weeks,
            duration: ['min' => $min, 'max' => $max],
            requiredSkills: $required,
            optionalSkills: $optional,
            startDayWeights: $weights,
            splitMinimumPriority: $priority,
            sla: [
                'in' => self::duration($data['sla']['in'] ?? null, 'stage4.sla.in'),
                'out' => self::duration($data['sla']['out'] ?? null, 'stage4.sla.out'),
                'start_based' => $startBased,
            ],
        );
    }

    private static function boolean(mixed $value, string $path): string
    {
        if (! is_bool($value)) {
            throw new InvalidDatasetSpec("{$path} must be true or false.");
        }

        return $value ? 'true' : 'false';
    }

    private static function seconds(string $duration): int
    {
        $start = new DateTimeImmutable('2000-01-01T00:00:00Z');

        return $start->add(new DateInterval($duration))->getTimestamp() - $start->getTimestamp();
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new InvalidDatasetSpec("{$path} must be a non-empty list.");
        }

        return $value;
    }

    private static function id(mixed $value, string $path): string
    {
        $value = self::text($value, $path);

        if (strlen($value) > self::MAX_ID_LENGTH) {
            throw new InvalidDatasetSpec("{$path} '{$value}' is longer than ".self::MAX_ID_LENGTH.' characters.');
        }

        return $value;
    }

    private static function text(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidDatasetSpec("{$path} is missing or blank.");
        }

        return $value;
    }

    private static function number(mixed $value, string $path): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidDatasetSpec("{$path} must be a number.");
        }

        return (float) $value;
    }

    private static function duration(mixed $value, string $path): string
    {
        $value = self::text($value, $path);

        try {
            new DateInterval($value);
        } catch (Throwable) {
            throw new InvalidDatasetSpec("{$path} '{$value}' is not an ISO 8601 duration such as PT30M or P7D.");
        }

        return $value;
    }

    private static function clock(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $value) !== 1) {
            throw new InvalidDatasetSpec("{$path} must be a local time HH:MM.");
        }

        return $value;
    }

    private static function timeZone(mixed $value, string $path): string
    {
        $value = self::text($value, $path);

        try {
            new DateTimeZone($value);
        } catch (Throwable) {
            throw new InvalidDatasetSpec("{$path} '{$value}' is not an IANA time zone such as America/Chicago.");
        }

        return $value;
    }

    /** Whole days in an ISO duration, counted from a fixed date (P1M is a calendar month). */
    private static function days(string $duration): int
    {
        $start = new DateTimeImmutable('2000-01-01T00:00:00Z');

        return (int) ceil(($start->add(new DateInterval($duration))->getTimestamp() - $start->getTimestamp()) / 86400);
    }
}
