<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Organisation_Permission, keyed by permission id; value is allow / allow_edit as T/F.
 */
class OrgPermissionsArea implements Area
{
    public function build(ComparisonContext $context): array
    {
        $valueMaps = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $map = [];

            foreach ($context->rows($environmentIndex, 'Organisation_Permission') as $row) {
                $map[$row['permission_id'] ?? ''] = Cells::allowEdit($row);
            }

            $valueMaps[] = $map;
        }

        $keys = ComparisonContext::unionKeys($valueMaps);
        usort($keys, Cells::compareText(...));

        $tab = new ComparisonTab('Org Permissions', '05_OrgPermissions.csv', ['Permission']);

        foreach ($keys as $key) {
            $tab->addRow([$key], ComparisonContext::valuesFor($valueMaps, $key));
        }

        return [$tab];
    }
}
