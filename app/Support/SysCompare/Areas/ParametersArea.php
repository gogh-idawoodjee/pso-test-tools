<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Profile_Parameter, keyed by profile + parameter id + application type.
 * Any parameter whose id contains "key" and has a value is masked.
 */
class ParametersArea implements Area
{
    public const string MASKED_KEY = '[API key set]';

    public function build(ComparisonContext $context): array
    {
        $valueMaps = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $map = [];

            foreach ($context->rows($environmentIndex, 'Profile_Parameter') as $row) {
                $parameterId = $row['parameter_id'] ?? '';
                $key = implode(Cells::KEY_SEPARATOR, [
                    $row['profile_id'] ?? '',
                    $parameterId,
                    $row['parameter_application_type_id'] ?? '',
                ]);
                $value = $row['parameter_value'] ?? null;

                if (stripos($parameterId, 'key') !== false && $value !== null && $value !== '') {
                    $context->noteApiKeyValue($value);
                    $map[$key] = self::MASKED_KEY;
                } else {
                    $map[$key] = Cells::format($value);
                }
            }

            $valueMaps[] = $map;
        }

        $keys = ComparisonContext::unionKeys($valueMaps);

        usort($keys, static function (string $left, string $right): int {
            [$leftProfile, $leftParameter, $leftType] = explode(Cells::KEY_SEPARATOR, $left);
            [$rightProfile, $rightParameter, $rightType] = explode(Cells::KEY_SEPARATOR, $right);

            return Cells::profileOrder($leftProfile) <=> Cells::profileOrder($rightProfile)
                ?: Cells::compareText($leftProfile, $rightProfile)
                ?: Cells::compareText($leftParameter, $rightParameter)
                ?: Cells::compareText($leftType, $rightType);
        });

        $tab = new ComparisonTab('Parameters', '01_Parameters.csv', ['Profile', 'Parameter', 'AppType'], ['Definition']);

        foreach ($keys as $key) {
            [$profile, $parameter, $applicationType] = explode(Cells::KEY_SEPARATOR, $key);

            $tab->addRow(
                [$profile, $parameter, $applicationType],
                ComparisonContext::valuesFor($valueMaps, $key),
                ['Definition' => $context->definitions->find($parameter)?->displayText() ?? ''],
            );
        }

        return [$tab];
    }
}
