<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Profiles, terminology, exception type data and the organisation record.
 */
class ProfilesAndOtherArea implements Area
{
    private const array ORGANISATION_FIELDS = ['account_id', 'name', 'status', 'organisation_id'];

    public function build(ComparisonContext $context): array
    {
        $tab = new ComparisonTab('Profiles & Other', '08_ProfilesAndOther.csv', ['Item', 'Key', 'Notes']);

        $this->addProfiles($tab, $context);
        $this->addTerminology($tab, $context);
        $this->addExceptionTypeData($tab, $context);
        $this->addOrganisation($tab, $context);

        return [$tab];
    }

    private function addProfiles(ComparisonTab $tab, ComparisonContext $context): void
    {
        $profilesByEnvironment = [];
        $descriptions = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $map = [];

            foreach ($context->rows($environmentIndex, 'Profile') as $row) {
                $id = $row['id'] ?? '';
                $map[$id] = $row;

                $description = $row['description'] ?? '';

                if ($description !== '' && ! isset($descriptions[$id])) {
                    $descriptions[$id] = $description;
                }
            }

            $profilesByEnvironment[] = $map;
        }

        $ids = ComparisonContext::unionKeys($profilesByEnvironment);
        usort($ids, Cells::compareText(...));

        foreach ($ids as $id) {
            $values = array_map(
                static fn (array $profiles): string => isset($profiles[$id]) ? Cells::format($profiles[$id]['profile_type'] ?? null) : Cells::ABSENT,
                $profilesByEnvironment,
            );

            $tab->addRow(['Profile', $id, $descriptions[$id] ?? ''], $values);
        }
    }

    private function addTerminology(ComparisonTab $tab, ComparisonContext $context): void
    {
        $termsByEnvironment = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $map = [];

            foreach ($context->rows($environmentIndex, 'Terminology_Organisation') as $row) {
                $map[$row['term'] ?? ''] = Cells::format($row['alias_cap_singular'] ?? null);
            }

            $termsByEnvironment[] = $map;
        }

        $terms = ComparisonContext::unionKeys($termsByEnvironment);
        usort($terms, Cells::compareText(...));

        foreach ($terms as $term) {
            $tab->addRow(['Terminology', $term, 'alias (capitalised singular)'], ComparisonContext::valuesFor($termsByEnvironment, $term));
        }
    }

    private function addExceptionTypeData(ComparisonTab $tab, ComparisonContext $context): void
    {
        $dataByEnvironment = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $map = [];

            foreach ($context->rows($environmentIndex, 'Org_Schedule_Exc_Type_Data') as $row) {
                $key = implode(Cells::KEY_SEPARATOR, [
                    $row['profile_id'] ?? '',
                    $row['schedule_exception_type_id'] ?? '',
                    $row['sequence'] ?? '',
                ]);
                $map[$key] = 'active='.($row['active'] ?? '');
            }

            $dataByEnvironment[] = $map;
        }

        $keys = ComparisonContext::unionKeys($dataByEnvironment);
        usort($keys, Cells::compareText(...));

        foreach ($keys as $key) {
            [$profile, $typeId, $sequence] = explode(Cells::KEY_SEPARATOR, $key);

            $tab->addRow(
                ['Exc type data', "{$profile} / type {$typeId} / seq {$sequence}", ''],
                ComparisonContext::valuesFor($dataByEnvironment, $key),
            );
        }
    }

    private function addOrganisation(ComparisonTab $tab, ComparisonContext $context): void
    {
        foreach (self::ORGANISATION_FIELDS as $field) {
            $values = [];

            foreach (array_keys($context->environments) as $environmentIndex) {
                $organisation = $context->rows($environmentIndex, 'Organisation')[0] ?? null;

                $values[] = $organisation !== null && array_key_exists($field, $organisation)
                    ? Cells::format($organisation[$field])
                    : Cells::ABSENT;
            }

            $tab->addRow(['Organisation', $field, ''], $values);
        }
    }
}
