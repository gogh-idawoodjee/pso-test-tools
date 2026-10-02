<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Profile_Parameter, keyed by profile + parameter id + application type, compared as
 * EFFECTIVE values: an explicit value, else the catalog default (the export lists only the
 * parameters that were set, so an absent parameter is using its default), else "(absent)"
 * when the parameter is not in the catalog. Profiles do not inherit from each other.
 *
 * A row's status is "Same" (identical as shown), "Same (default)" (differs as shown only
 * because of defaults) or "DIFF" (effective values differ). Any parameter whose id contains
 * "key" is masked.
 */
class ParametersArea implements Area
{
    public const string MASKED_KEY = '[API key set]';

    public function build(ComparisonContext $context): array
    {
        $environmentIndexes = array_keys($context->environments);
        $explicitKeys = [];

        foreach ($environmentIndexes as $environmentIndex) {
            foreach ($context->parameters($environmentIndex)->explicit as $key => $value) {
                $explicitKeys[(string) $key] = true;

                if (stripos(explode(Cells::KEY_SEPARATOR, (string) $key)[1], 'key') !== false && $value !== null && $value !== '') {
                    $context->noteApiKeyValue($value);
                }
            }
        }

        $keys = array_map('strval', array_keys($explicitKeys));

        usort($keys, static function (string $left, string $right): int {
            [$leftProfile, $leftParameter, $leftType] = explode(Cells::KEY_SEPARATOR, $left);
            [$rightProfile, $rightParameter, $rightType] = explode(Cells::KEY_SEPARATOR, $right);

            return Cells::profileOrder($leftProfile) <=> Cells::profileOrder($rightProfile)
                ?: Cells::compareText($leftProfile, $rightProfile)
                ?: Cells::compareText($leftParameter, $rightParameter)
                ?: Cells::compareText($leftType, $rightType);
        });

        $tab = new ComparisonTab('Parameters', '01_Parameters.csv', ['Profile', 'Parameter', 'AppType'], ['Default', 'Definition']);

        foreach ($keys as $key) {
            [$profile, $parameter, $application] = explode(Cells::KEY_SEPARATOR, $key);

            $shown = [];
            $compared = [];

            foreach ($environmentIndexes as $environmentIndex) {
                $effective = $context->resolver->resolve($context->parameters($environmentIndex), $profile, $parameter, $application);
                $shown[] = $effective->display;
                $compared[] = $effective->compare;
            }

            $tab->addRow(
                [$profile, $parameter, $application],
                $shown,
                [
                    'Default' => $context->resolver->defaultLabel($parameter, $application),
                    'Definition' => $context->definitions->find($parameter, $application, $context->catalog)?->displayText() ?? '',
                ],
                $compared,
            );
        }

        return [$tab];
    }
}
