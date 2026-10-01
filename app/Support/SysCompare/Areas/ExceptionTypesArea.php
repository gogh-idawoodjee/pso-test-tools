<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Org_Schedule_Exception_Type, keyed by profile + type id.
 * The compared value is "on/off / attn N / act X".
 */
class ExceptionTypesArea implements Area
{
    public function build(ComparisonContext $context): array
    {
        $valueMaps = [];
        $descriptions = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $map = [];

            foreach ($context->rows($environmentIndex, 'Org_Schedule_Exception_Type') as $row) {
                $key = ($row['profile_id'] ?? '').Cells::KEY_SEPARATOR.($row['schedule_exception_type_id'] ?? '');
                $activation = $row['activation_setting'] ?? '';

                $map[$key] = sprintf(
                    '%s / attn %s / act %s',
                    ($row['active'] ?? null) === 'true' ? 'on' : 'off',
                    $row['attention_value'] ?? '',
                    $activation === '' ? '-' : $activation,
                );

                $description = $row['description'] ?? '';

                if ($description !== '' && ! isset($descriptions[$key])) {
                    $descriptions[$key] = $description;
                }
            }

            $valueMaps[] = $map;
        }

        $keys = ComparisonContext::unionKeys($valueMaps);

        usort($keys, static function (string $left, string $right): int {
            [$leftProfile, $leftType] = explode(Cells::KEY_SEPARATOR, $left);
            [$rightProfile, $rightType] = explode(Cells::KEY_SEPARATOR, $right);

            return Cells::profileOrder($leftProfile) <=> Cells::profileOrder($rightProfile)
                ?: Cells::compareText($leftProfile, $rightProfile)
                ?: Cells::compareInteger($leftType, $rightType);
        });

        $tab = new ComparisonTab('Exception Types', '02_ExceptionTypes.csv', ['Profile', 'TypeId', 'Description']);

        foreach ($keys as $key) {
            [$profile, $typeId] = explode(Cells::KEY_SEPARATOR, $key);

            $tab->addRow([$profile, $typeId, $descriptions[$key] ?? ''], ComparisonContext::valuesFor($valueMaps, $key));
        }

        return [$tab];
    }
}
