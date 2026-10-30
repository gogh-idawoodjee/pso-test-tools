<?php

namespace App\Support\DatasetBuilder;

use App\Support\DatasetBuilder\Spec\DatasetSpec;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Stage 1 of the Dataset Builder: a LOAD with regions, locations, skills,
 * resource types, the commit rule set, resources, their regions, skills and
 * extra-region availability, shifts, breaks and object groups. No activities.
 * Stage 1R: when the spec says `modelling`, the matching modelling data
 * (ModellingData, an .arp) from the same model, and a slim LOAD that carries
 * only what RAM does not: Input_Reference, Source_Data RAM, shifts, breaks and
 * object groups.
 *
 * Entities go out in the cheat sheet's emit order
 * (docs/dataset-builder/stage1-entity-cheatsheet.md). A resource carries only
 * what its type does not, plus the seed's `overrides`. The same spec, seed and
 * `now` give byte-identical files: every random draw comes from one seeded
 * engine, in spec order (DatasetModel).
 */
class DatasetGenerator
{
    public const string STAGE = 'stage1';

    public const string MODELLING_STAGE = 'stage1r';

    public function generate(DatasetSpec $spec, DateTimeImmutable $now, ?string $directory = null): GeneratedDataset
    {
        $model = DatasetModel::build($spec, $now);
        $now = $model->now;
        $directory ??= storage_path("app/datasets/{$spec->datasetId}");
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$now->format('Ymd-Hi').'-'.self::STAGE.'.xml';

        $writer = new DsScheduleDataWriter($path, $spec->modelling ? DsScheduleDataWriter::SLIM : null);
        $summary = new DatasetSummary($spec, $now);

        foreach ($spec->resources as $resource) {
            $summary->resource($resource, $spec->resourceTypes[$resource->resourceType]);

            foreach ($resource->extraRegions as $regionId) {
                $summary->extraRegion($resource, $regionId);
            }
        }

        $writer->row('Input_Reference', [
            'datetime' => $now->format('Y-m-d\TH:i:sP'),
            'id' => $model->updateId(),
            'input_type' => 'LOAD',
            'organisation_id' => $spec->organisationId,
            'dataset_id' => $spec->datasetId,
            'schedule_data' => 'CONTINUOUS',
            'load_status' => '0',
            'duration' => $spec->inputReference['duration'],
            'appointment_window_duration' => $spec->inputReference['appointment_window_duration'],
            'process_type' => $spec->inputReference['process_type'],
        ]);

        if ($spec->modelling) {
            // Slim: everything else is the modelling data's, and the shifts hang off it.
            $writer->row('Source_Data', ['source_data_type_id' => 'RAM', 'sequence' => '1']);
            $writer->row('Source_Data_Parameter', [
                'source_data_type_id' => 'RAM',
                'sequence' => '1',
                'parameter_name' => 'rota_id',
                'parameter_value' => $spec->datasetId,
            ]);
        } else {
            $this->writeResourceModel($writer, $model);
        }

        $breaks = [];

        foreach ($spec->resources as $resource) {
            $type = $spec->resourceTypes[$resource->resourceType];
            $region = $spec->regions[$resource->region];

            foreach (ShiftFactory::shifts($resource, $type, $region, $now, $spec->horizonDays) as $shift) {
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
        $modelling = null;

        if ($spec->modelling) {
            $modellingPath = $directory.'/'.$now->format('Ymd-Hi').'-'.self::MODELLING_STAGE.'.arp';
            $modelling = ['path' => $modellingPath, ...ModellingData::write($model, $modellingPath)];
        }

        return new GeneratedDataset($path, $bytes, $summary->toArray($path, $bytes, $modelling), $modelling['path'] ?? null);
    }

    /**
     * The stage 1 LOAD's regions, locations, skills, types, rule set, resources
     * and their regions, skills and extra-region availability: written only
     * when there is no modelling data to carry them.
     */
    private function writeResourceModel(DsScheduleDataWriter $writer, DatasetModel $model): void
    {
        $spec = $model->spec;

        foreach ($spec->regions as $region) {
            $writer->row('Region', ['id' => $region->id, 'description' => $region->description]);
        }

        foreach ($model->locations as $location) {
            $writer->row('Location', $location);
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
                'cost_ph' => Decimal::format($type->costPh),
                'cost_phot' => Decimal::format($type->costPhot),
                'cost_km' => Decimal::format($type->costKm),
                'speedfactor' => '1',
                'utilisation' => (string) $type->utilisation,
                'out_of_region_multiplier' => '0',
                'resource_class_id' => 'STANDARD',
            ]);
        }

        $this->writeRuleSet($writer, $spec);

        foreach ($spec->resources as $resource) {
            $type = $spec->resourceTypes[$resource->resourceType];
            $start = $model->startLocation($resource);

            // The type carries class, travel, costs and utilisation; the resource only its own exceptions.
            $writer->row('Resources', [
                'id' => $resource->id,
                'first_name' => $resource->firstName,
                'surname' => $resource->surname,
                'resource_type_id' => $type->id,
                'location_id_start' => $start,
                'location_id_end' => $start,
                ...$resource->overrides,
                'rule_collection_id' => $spec->ruleCollection['id'],
                'memo' => '*',
            ]);
        }

        foreach ($spec->resources as $resource) {
            $writer->row('Resource_Region', ['region_id' => $resource->region, 'resource_id' => $resource->id, 'override_priority' => '1']);
        }

        foreach ($spec->resources as $resource) {
            foreach ($resource->skills as $skill) {
                $writer->row('Resource_Skill', ['skill_id' => $skill, 'resource_id' => $resource->id]);
            }
        }

        $this->writeExtraRegions($writer, $spec, $model->now);
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
    private function writeExtraRegions(DsScheduleDataWriter $writer, DatasetSpec $spec, DateTimeImmutable $now): void
    {
        $links = [];

        foreach ($spec->resources as $resource) {
            foreach ($resource->extraRegions as $regionId) {
                [$start, $end] = ShiftFactory::window($now, new DateTimeZone($spec->regions[$regionId]->timeZone), $spec->horizonDays);
                $availabilityId = "AV-{$resource->id}-{$regionId}";

                $writer->row('Availability', [
                    'id' => $availabilityId,
                    'datetime_start' => ShiftFactory::utc($start),
                    'datetime_end' => ShiftFactory::utc($end),
                ]);

                $links[] = [
                    'id' => "RRA-{$resource->id}-{$regionId}",
                    'resource_id' => $resource->id,
                    'region_id' => $regionId,
                    'availability_id' => $availabilityId,
                    'override_priority' => '1',
                ];
            }
        }

        foreach ($links as $link) {
            $writer->row('Resource_Region_Availability', $link);
        }
    }
}
