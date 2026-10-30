<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\DatasetSpec;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Stage 1 of the Dataset Builder: a LOAD with regions, locations, skills,
 * resource types, the commit rule set, resources, their regions, skills and
 * extra-region availability, shifts, breaks and object groups. No activities.
 *
 * Entities go out in the cheat sheet's emit order
 * (docs/dataset-builder/stage1-entity-cheatsheet.md). The same spec, seed and
 * `now` give a byte-identical file: every random draw comes from one seeded
 * engine, in spec order.
 */
class DatasetGenerator
{
    public const string STAGE = 'stage1';

    public function generate(DatasetSpec $spec, DateTimeImmutable $now, ?string $directory = null): GeneratedDataset
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $directory ??= storage_path("app/datasets/{$spec->datasetId}");
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$now->format('Ymd-Hi').'-'.self::STAGE.'.xml';

        $random = new Randomizer(new Mt19937($spec->seed));
        $locations = new LocationFactory($random);
        $writer = new DsScheduleDataWriter($path);
        $summary = new DatasetSummary($spec, $now);

        $writer->row('Input_Reference', [
            'datetime' => $now->format('Y-m-d\TH:i:sP'),
            'id' => (string) $now->getTimestamp(),
            'input_type' => 'LOAD',
            'organisation_id' => $spec->organisationId,
            'dataset_id' => $spec->datasetId,
            'schedule_data' => 'CONTINUOUS',
            'load_status' => '0',
            'duration' => $spec->inputReference['duration'],
            'appointment_window_duration' => $spec->inputReference['appointment_window_duration'],
            'process_type' => $spec->inputReference['process_type'],
        ]);

        foreach ($spec->regions as $region) {
            $writer->row('Region', ['id' => $region->id, 'description' => $region->description]);
        }

        foreach ($spec->regions as $region) {
            if ($region->depot !== null) {
                $writer->row('Location', LocationFactory::depot($region));
            }
        }

        foreach ($spec->resources as $resource) {
            if (! $spec->resourceTypes[$resource->resourceType]->startsAtDepot()) {
                $writer->row('Location', $locations->home($resource, $spec->regions[$resource->region]));
            }
        }

        foreach ($spec->skills as $id => $description) {
            $writer->row('Skill', ['id' => $id, 'description' => $description]);
        }

        foreach ($spec->resourceTypes as $type) {
            $writer->row('Resource_Type', [
                'id' => $type->id,
                'description' => $type->description,
                'travel_to' => $type->travelTo,
                'travel_from' => $type->travelFrom,
                'max_travel' => $type->maxTravel,
                'cost_ph' => self::number($type->costPh),
                'cost_phot' => self::number($type->costPhot),
                'cost_km' => self::number($type->costKm),
                'speedfactor' => '1',
                'utilisation' => (string) $type->utilisation,
                'out_of_region_multiplier' => '0',
                'resource_class_id' => 'STANDARD',
            ]);
        }

        $this->writeRuleSet($writer, $spec);

        foreach ($spec->resources as $resource) {
            $type = $spec->resourceTypes[$resource->resourceType];
            $start = $type->startsAtDepot() ? "D-{$resource->region}" : "H-{$resource->id}";

            $writer->row('Resources', [
                'id' => $resource->id,
                'first_name' => $resource->firstName,
                'surname' => $resource->surname,
                'resource_type_id' => $type->id,
                'resource_class_id' => 'STANDARD',
                'location_id_start' => $start,
                'location_id_end' => $start,
                'travel_to' => $type->travelTo,
                'travel_from' => $type->travelFrom,
                'cost_ph' => self::number($type->costPh),
                'cost_phot' => self::number($type->costPhot),
                'cost_km' => self::number($type->costKm),
                'utilisation' => (string) ($resource->utilisation ?? $type->utilisation),
                'rule_collection_id' => $spec->ruleCollection['id'],
                'memo' => '*',
            ]);
            $summary->resource($resource, $type);
        }

        foreach ($spec->resources as $resource) {
            $writer->row('Resource_Region', ['region_id' => $resource->region, 'resource_id' => $resource->id, 'override_priority' => '1']);
        }

        foreach ($spec->resources as $resource) {
            foreach ($resource->skills as $skill) {
                $writer->row('Resource_Skill', ['skill_id' => $skill, 'resource_id' => $resource->id]);
            }
        }

        $this->writeExtraRegions($writer, $spec, $now, $summary);

        $breaks = [];

        foreach ($spec->resources as $resource) {
            $type = $spec->resourceTypes[$resource->resourceType];
            $region = $spec->regions[$resource->region];

            foreach (ShiftFactory::shifts($resource, $type, $region, $now, $spec->horizonDays, $resource->utilisation ?? $type->utilisation) as $shift) {
                $writer->row('Shift', $shift['shift']);
                $breaks[] = $shift['break'];
                $summary->shift($resource, $type, $region, $shift);
            }
        }

        foreach ($breaks as $break) {
            $writer->row('Shift_Break', $break);
        }

        foreach ($spec->resources as $resource) {
            $writer->row('Object_Group', [
                'group_id' => $spec->datasetId,
                'id' => "{$spec->datasetId}-{$resource->id}",
                'resource_id' => $resource->id,
            ]);
        }

        $bytes = $writer->finish();

        return new GeneratedDataset($path, $bytes, $summary->toArray($path, $bytes));
    }

    private function writeRuleSet(DsScheduleDataWriter $writer, DatasetSpec $spec): void
    {
        $id = $spec->ruleCollection['id'];

        $writer->row('Rule', ['rule_type_id' => 'COMMIT', 'id' => $id, 'description' => 'Standard commit', 'global' => 'false']);
        $writer->row('Rule_Collection', ['id' => $id, 'description' => 'Standard']);
        $writer->row('Rule_Collection_Rule', ['rule_collection_id' => $id, 'rule_type_id' => 'COMMIT', 'rule_id' => $id]);

        foreach ($spec->ruleCollection['parameters'] as $parameter => $value) {
            $writer->row('Rule_Parameter', ['rule_type_id' => 'COMMIT', 'rule_id' => $id, 'parameter_id' => $parameter, 'parameter_value' => $value]);
        }
    }

    /**
     * Each extra region: an Availability over the whole horizon (midnight of
     * day -7 to midnight after day `horizon_days`, in that region's zone) and the
     * Resource_Region_Availability that uses it.
     */
    private function writeExtraRegions(DsScheduleDataWriter $writer, DatasetSpec $spec, DateTimeImmutable $now, DatasetSummary $summary): void
    {
        $links = [];

        foreach ($spec->resources as $resource) {
            foreach ($resource->extraRegions as $regionId) {
                $dayZero = ShiftFactory::dayZero($now, new DateTimeZone($spec->regions[$regionId]->timeZone));
                $availabilityId = "AV-{$resource->id}-{$regionId}";

                $writer->row('Availability', [
                    'id' => $availabilityId,
                    'datetime_start' => ShiftFactory::utc($dayZero->modify(sprintf('-%d days', ShiftFactory::DAYS_BEFORE))),
                    'datetime_end' => ShiftFactory::utc($dayZero->modify(sprintf('+%d days', $spec->horizonDays + 1))),
                ]);

                $links[] = [
                    'id' => "RRA-{$resource->id}-{$regionId}",
                    'resource_id' => $resource->id,
                    'region_id' => $regionId,
                    'availability_id' => $availabilityId,
                    'override_priority' => '1',
                ];
                $summary->extraRegion($resource, $regionId);
            }
        }

        foreach ($links as $link) {
            $writer->row('Resource_Region_Availability', $link);
        }
    }

    /** 45 -> "45", 0.6 -> "0.6": no trailing zeros, never scientific notation. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
