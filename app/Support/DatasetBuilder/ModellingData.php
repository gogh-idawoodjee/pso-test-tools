<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\ResourceTypeSpec;

/**
 * Stage 1R: the modelling (RAM, .arp) side of a dataset, from the same model as
 * its LOAD: divisions, locations, skills, types, the commit rule set,
 * resources, their divisions and skills, and per resource type a shift
 * category and a weekly shift template. No rota and no shifts: shifts keep
 * coming from the LOAD, and the planner adds its own on top.
 *
 * Field names are the stage 1R cheat sheet's
 * (docs/dataset-builder/stage1r-ram-entity-cheatsheet.md), in its emit order.
 */
class ModellingData
{
    /** @return array{bytes: int, counts: array<string, int>} */
    public static function write(DatasetModel $model, string $path): array
    {
        $spec = $model->spec;
        $writer = new DsModellingWriter($path);

        $writer->row('RAM_Update', [
            'organisation_id' => $spec->organisationId,
            'dataset_id' => $spec->datasetId,
            'id' => $model->updateId(),
            'ram_update_type_id' => 'LOAD',
            'description' => "{$spec->datasetId} modelling data, generated ".$model->now->format('Y-m-d H:i').'Z',
            'is_master_data' => 'true',
        ]);

        foreach ($spec->regions as $region) {
            $writer->row('RAM_Division', [
                'id' => $region->id,
                'description' => $region->description,
                'send' => 'true',
                'time_zone_id' => $region->timeZone,
            ]);
        }

        foreach ($model->locations as $location) {
            $writer->row('RAM_Location', [
                'id' => $location['id'],
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
                'name' => $location['name'],
                'address_line1' => $location['address_line1'],
                'city' => $location['city'],
                'post_code_zip' => $location['post_code_zip'],
            ]);
        }

        foreach ($spec->skills as $id => $description) {
            $writer->row('RAM_Skill', ['id' => $id, 'description' => $description, 'team_inheritable' => 'true']);
        }

        foreach ($spec->resourceTypes as $type) {
            $writer->row('RAM_Resource_Type', [
                'id' => $type->id,
                'description' => $type->description,
                'ram_resource_class_id' => 'PERSON',
                'cost_km' => Decimal::format($type->costKm),
                'cost_ph' => Decimal::format($type->costPh),
                'cost_phot' => Decimal::format($type->costPhot),
                'travel_to' => $type->travelTo,
                'travel_from' => $type->travelFrom,
                'max_travel' => $type->maxTravel,
                'utilisation' => (string) $type->utilisation,
                'hire_cost' => '4000',
                'release_cost' => '4000',
                'relocation_cost' => '2000',
                'out_of_region_multiplier' => '0',
                'speedfactor' => '1',
            ]);
        }

        $ruleId = $spec->ruleCollection['id'];
        $writer->row('RAM_Rule', ['ram_rule_type_id' => 'COMMIT', 'id' => $ruleId, 'global' => 'false']);
        $writer->row('RAM_Rule_Collection', ['id' => $ruleId]);
        $writer->row('RAM_Rule_Collection_Rule', ['ram_rule_collection_id' => $ruleId, 'ram_rule_type_id' => 'COMMIT', 'ram_rule_id' => $ruleId]);

        foreach ($spec->ruleCollection['parameters'] as $parameter => $value) {
            $writer->row('RAM_Rule_Parameter', ['ram_rule_type_id' => 'COMMIT', 'ram_rule_id' => $ruleId, 'parameter_id' => $parameter, 'parameter_value' => $value]);
        }

        foreach ($spec->resources as $resource) {
            $start = $model->startLocation($resource);

            $writer->row('RAM_Resource', [
                'id' => $resource->id,
                'first_name' => $resource->firstName,
                'surname' => $resource->surname,
                'ram_resource_type_id' => $resource->resourceType,
                'ram_location_id_start' => $start,
                'ram_location_id_end' => $start,
                ...$resource->overrides,
                'ram_rule_collection_id' => $ruleId,
            ]);
        }

        // Home division first, then each extra region: permanent in RAM; the LOAD's Resource_Region_Availability bounds it in time.
        foreach ($spec->resources as $resource) {
            foreach ([$resource->region, ...$resource->extraRegions] as $division) {
                $writer->row('RAM_Resource_Division', ['ram_division_id' => $division, 'ram_resource_id' => $resource->id, 'override_priority' => '1']);
            }
        }

        foreach ($spec->resources as $resource) {
            foreach ($resource->skills as $skill) {
                $writer->row('RAM_Resource_Skill', ['ram_resource_id' => $resource->id, 'ram_skill_id' => $skill]);
            }
        }

        foreach ($spec->resourceTypes as $type) {
            $writer->row('RAM_Shift_Category', ['id' => $type->id, 'colour' => $type->shiftCategoryColour, 'productive' => 'true']);
        }

        foreach ($spec->resourceTypes as $type) {
            $writer->row('RAM_Shift_Template', [
                'id' => $type->id,
                'description' => self::templateDescription($type),
                'ram_shift_category_id' => $type->id,
                'recurrence_days' => '7',
                'start_day' => '1',
                'duration' => self::clockDuration($type->shiftEnd, $type->shiftStart),
                'split_allowed' => 'true',
                'split_priority' => '1',
                'precedence' => '1',
                'repeat_preference' => '1',
            ]);
        }

        foreach ($spec->resourceTypes as $type) {
            foreach (self::workingDays($type) as $sequence => $weekday) {
                $writer->row('RAM_Shift_Template_Item', [
                    'ram_shift_template_id' => $type->id,
                    'sequence' => (string) $sequence,
                    'day_increment' => (string) ($weekday - 1),
                    'time_increment' => self::clockDuration($type->shiftStart),
                ]);
            }
        }

        foreach ($spec->resourceTypes as $type) {
            foreach (array_keys(self::workingDays($type)) as $sequence) {
                $writer->row('RAM_Shift_Template_Item_Break', [
                    'id' => "{$type->id}-{$sequence}",
                    'ram_shift_template_id' => $type->id,
                    'sequence' => (string) $sequence,
                    'duration' => $type->breakDuration,
                    'earliest_start' => $type->breakEarliestOffset,
                    'latest_start' => $type->breakLatestOffset,
                ]);
            }
        }

        $bytes = $writer->finish();

        return ['bytes' => $bytes, 'counts' => $writer->counts()];
    }

    /** @return array<int, int> sequence (from 1) => ISO weekday, Monday first */
    public static function workingDays(ResourceTypeSpec $type): array
    {
        $days = $type->shiftDays;
        sort($days);

        return array_combine(range(1, count($days)), $days);
    }

    /** "08:00" -> PT8H, "16:30" less "08:00" -> PT8H30M. */
    public static function clockDuration(string $clock, string $since = '00:00'): string
    {
        $minutes = self::minutes($clock) - self::minutes($since);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'PT'.($hours > 0 ? "{$hours}H" : '').($rest > 0 || $hours === 0 ? "{$rest}M" : '');
    }

    private static function minutes(string $clock): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $clock));

        return $hours * 60 + $minutes;
    }

    /** "Field Technician Mon-Fri 08:00-16:30"; days that are not one run are listed. */
    private static function templateDescription(ResourceTypeSpec $type): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = array_values(self::workingDays($type));
        $run = $days === range($days[0], $days[0] + count($days) - 1);
        $label = $run && count($days) > 1
            ? $names[$days[0]].'-'.$names[end($days)]
            : implode(' ', array_map(static fn (int $day): string => $names[$day], $days));

        return "{$type->description} {$label} {$type->shiftStart}-{$type->shiftEnd}";
    }
}
