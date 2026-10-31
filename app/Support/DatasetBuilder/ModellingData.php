<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\ResourceTypeSpec;
use DateTimeZone;

/**
 * Stage 1R: the modelling (RAM, .arp) side of a dataset, from the same model as
 * its LOAD: divisions, locations, skills, types, the commit rule set,
 * resources, their divisions (an extra region bounded by a time pattern) and
 * skills, per resource type a shift category and a weekly shift template,
 * the master rota (every shift as a rota item, since the 1R FIX on #60), and
 * (stage 1R2) the activity reference data: SLA types, activity types and the
 * regions' appointment templates. The planner adds its own shifts on top.
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

        // Home division first, then each extra region, which the time pattern below also bounds in time.
        foreach ($spec->resources as $resource) {
            foreach ([$resource->region, ...$resource->extraRegions] as $division) {
                $writer->row('RAM_Resource_Division', ['ram_division_id' => $division, 'ram_resource_id' => $resource->id, 'override_priority' => '1']);
            }
        }

        // Each extra region's window: midnight of day -7 in that division's zone, for the whole span shifts can fall in.
        foreach ($spec->resources as $resource) {
            foreach ($resource->extraRegions as $division) {
                [$start] = ShiftFactory::window($model->now, new DateTimeZone($spec->regions[$division]->timeZone), $spec->horizonDays);

                $writer->row('RAM_Time_Pattern', [
                    'id' => "TP-{$resource->id}-{$division}",
                    'base_time' => ShiftFactory::utc($start),
                    'duration' => 'P'.ShiftFactory::windowDays($spec->horizonDays).'D',
                ]);
            }
        }

        foreach ($spec->resources as $resource) {
            foreach ($resource->extraRegions as $division) {
                $writer->row('RAM_Resource_Division_Avail', [
                    'id' => "RDA-{$resource->id}-{$division}",
                    'ram_resource_id' => $resource->id,
                    'ram_division_id' => $division,
                    'ram_time_pattern_id' => "TP-{$resource->id}-{$division}",
                    'override_priority' => '1',
                ]);
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

        self::writeRota($writer, $model);

        foreach ($spec->slaTypes as $id => $type) {
            $writer->row('RAM_SLA_Type', [
                'id' => $id,
                'description' => $type['description'],
                'start_proportion' => $type['start_proportion'],
                'end_proportion' => $type['end_proportion'],
                'curve_shape' => $type['curve_shape'],
                'activity_ageing_factor' => $type['activity_ageing_factor'],
                'generate_jeopardy_exceptions' => $type['generate_jeopardy_exceptions'],
                'ram_sla_mechanism_type_id' => $type['mechanism_type'],
            ]);
        }

        foreach ($spec->activityTypes as $id => $type) {
            $writer->row('RAM_Activity_Type', [
                'id' => $id,
                'description' => $type['description'],
                'duration' => $type['duration'],
                'base_value' => $type['base_value'],
                'supply_locations' => 'false',
                'split_allowed' => $type['split_allowed'],
                'interrupt' => 'false',
                'minimum_split' => $type['min_split'],
                'cost_of_split' => $type['cost_of_split'],
                'priority' => $type['priority'],
                'on_location_duration_factor' => '1',
            ]);
        }

        $templates = $model->appointmentTemplates();

        // No slot usage rule set, and none of the scheduling-only template fields (stage 1R2 cheat sheet).
        foreach ($templates as $template) {
            $writer->row('RAM_Appointment_Template', [
                'id' => $template['id'],
                'description' => $template['description'],
                'day_pattern' => $template['day_pattern'],
                'number_of_slots' => $template['number_of_slots'],
                'exclude_to_template_start' => 'false',
                'start_based' => 'true',
                'offer_expiry_window' => 'PT3M',
                'default_template' => 'false',
                'blocking' => 'false',
                'time_zone' => $template['time_zone'],
            ]);
        }

        foreach ($templates as $template) {
            foreach ($template['items'] as $item) {
                $writer->row('RAM_Appointment_Template_Item', [
                    'ram_appointment_template_id' => $template['id'],
                    'slot_start_time' => $item['start'],
                    'slot_end_time' => $item['end'],
                ]);
            }
        }

        $bytes = $writer->finish();

        return ['bytes' => $bytes, 'counts' => $writer->counts()];
    }

    /**
     * The master rota (1R FIX on #60): one header named after the dataset, and
     * per resource a membership period and an alteration over the whole shift
     * span (day -7 00:00 to midnight after day `horizon_days`, in its home
     * region's zone), then every shift as a rota item with its break. Items
     * keep the shift ids and instants, and carry the Monday split rule
     * themselves: the template's one split_priority cannot.
     */
    private static function writeRota(DsModellingWriter $writer, DatasetModel $model): void
    {
        $spec = $model->spec;
        $rota = $spec->datasetId;

        $writer->row('RAM_Rota', ['id' => $rota, 'ram_rota_type_id' => 'MASTER', 'modified' => 'false']);

        $spans = [];

        foreach ($spec->resources as $resource) {
            [$start, $end] = ShiftFactory::window($model->now, new DateTimeZone($spec->regions[$resource->region]->timeZone), $spec->horizonDays);
            $spans[$resource->id] = ['start_datetime' => ShiftFactory::utc($start), 'end_datetime' => ShiftFactory::utc($end)];
        }

        foreach ($spec->resources as $resource) {
            $writer->row('RAM_Rota_Membership_Period', ['ram_rota_id' => $rota, 'id' => "RMP-{$resource->id}", 'ram_resource_id' => $resource->id, ...$spans[$resource->id]]);
        }

        foreach ($spec->resources as $resource) {
            $writer->row('RAM_Rota_Alteration', ['ram_rota_id' => $rota, 'id' => $resource->id, 'ram_resource_id' => $resource->id, ...$spans[$resource->id]]);
        }

        $descriptions = array_map(self::templateDescription(...), $spec->resourceTypes);

        foreach ($model->shifts as $row) {
            $shift = $row['shift']['shift'];

            $writer->row('RAM_Rota_Item', [
                'ram_rota_id' => $rota,
                'id' => $shift['id'],
                'ram_resource_id' => $shift['resource_id'],
                'start_datetime' => $shift['start_datetime'],
                'end_datetime' => $shift['end_datetime'],
                'ram_shift_category_id' => $row['type']->id,
                'ram_shift_template_id' => $row['type']->id,
                'split_allowed' => $shift['split_allowed'],
                'split_priority' => $shift['split_priority'],
                'description' => $descriptions[$row['type']->id],
                'modified' => 'false',
            ]);
        }

        foreach ($model->shifts as $row) {
            $break = $row['shift']['break'];

            $writer->row('RAM_Rota_Item_Break', [
                'ram_rota_id' => $rota,
                'id' => $break['id'],
                'ram_rota_item_id' => $break['shift_id'],
                'duration' => $break['duration'],
                'earliest_start' => $break['earliest_start_offset'],
                'latest_start' => $break['latest_start_offset'],
            ]);
        }
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
