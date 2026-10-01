<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Groups and Group_Permission. Group names are matched case-insensitively, so
 * ST_Ops_Mgr and ST_Ops_MGR are one group; the spelling difference is flagged.
 *
 * Group_Permission includes explicit denies (allow=false), so a row count is
 * "permission rows (allow + deny)" and never a count of permissions granted.
 */
class GroupsArea implements Area
{
    public function build(ComparisonContext $context): array
    {
        /** @var array<string, array<int, string>> $spellings lower-cased group => environment index => spelling */
        $spellings = [];
        /** @var list<array<string, array<string, string>>> $groupRows environment index => lower-cased group => row */
        $groupRows = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $rowsByGroup = [];

            foreach ($context->rows($environmentIndex, 'Groups') as $row) {
                $id = $row['id'] ?? '';
                $lowerCased = strtolower($id);
                $rowsByGroup[$lowerCased] = $row;
                $spellings[$lowerCased][$environmentIndex] = $id;
            }

            $groupRows[] = $rowsByGroup;
        }

        $display = fn (string $lowerCased): string => $this->displayName($lowerCased, $spellings, $context->baselineIndex);

        return [
            $this->permissionsTab($context, $spellings, $display),
            $this->groupsTab($context, $spellings, $groupRows, $display),
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $spellings
     * @param  callable(string): string  $display
     */
    private function permissionsTab(ComparisonContext $context, array $spellings, callable $display): ComparisonTab
    {
        $valueMaps = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $map = [];

            foreach ($context->rows($environmentIndex, 'Group_Permission') as $row) {
                $key = strtolower($row['group_id'] ?? '').Cells::KEY_SEPARATOR.($row['permission_id'] ?? '');
                $map[$key] = Cells::allowEdit($row);
            }

            $valueMaps[] = $map;
        }

        $keys = ComparisonContext::unionKeys($valueMaps);

        usort($keys, static function (string $left, string $right) use ($display): int {
            [$leftGroup, $leftPermission] = explode(Cells::KEY_SEPARATOR, $left);
            [$rightGroup, $rightPermission] = explode(Cells::KEY_SEPARATOR, $right);

            return strcmp(strtolower($display($leftGroup)), strtolower($display($rightGroup)))
                ?: Cells::compareText($leftPermission, $rightPermission);
        });

        $tab = new ComparisonTab('Group Permissions', '03_GroupPermissions.csv', ['Group', 'Permission', 'Note']);

        foreach ($keys as $key) {
            [$group, $permission] = explode(Cells::KEY_SEPARATOR, $key);
            $note = $this->spellingDiffers($group, $spellings) ? 'group spelling differs across environments' : '';

            $tab->addRow([$display($group), $permission, $note], ComparisonContext::valuesFor($valueMaps, $key));
        }

        return $tab;
    }

    /**
     * @param  array<string, array<int, string>>  $spellings
     * @param  list<array<string, array<string, string>>>  $groupRows
     * @param  callable(string): string  $display
     */
    private function groupsTab(ComparisonContext $context, array $spellings, array $groupRows, callable $display): ComparisonTab
    {
        $permissionRowCounts = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            foreach ($context->rows($environmentIndex, 'Group_Permission') as $row) {
                $lowerCased = strtolower($row['group_id'] ?? '');
                $permissionRowCounts[$environmentIndex][$lowerCased] = ($permissionRowCounts[$environmentIndex][$lowerCased] ?? 0) + 1;
            }
        }

        $groups = array_map('strval', array_keys($spellings));
        usort($groups, static fn (string $left, string $right): int => strcmp(strtolower($display($left)), strtolower($display($right))));

        $tab = new ComparisonTab('Groups', '04_Groups.csv', ['Group', 'Attribute']);
        $environmentIndexes = array_keys($context->environments);

        foreach ($groups as $group) {
            $presentAs = [];
            $parent = [];
            $description = [];
            $counts = [];

            foreach ($environmentIndexes as $environmentIndex) {
                $row = $groupRows[$environmentIndex][$group] ?? null;

                $presentAs[] = $row !== null ? ($row['id'] ?? '') : Cells::ABSENT;
                $parent[] = $row !== null ? Cells::format($row['group_id'] ?? null) : Cells::ABSENT;
                $description[] = $row !== null ? Cells::format($row['description'] ?? null) : Cells::ABSENT;
                $counts[] = (string) ($permissionRowCounts[$environmentIndex][$group] ?? 0);
            }

            $name = $display($group);
            $tab->addRow([$name, 'Present as'], $presentAs);
            $tab->addRow([$name, 'Parent group'], $parent);
            $tab->addRow([$name, 'Description'], $description);
            $tab->addRow([$name, 'Permission rows, allow + deny (count)'], $counts);
        }

        return $tab;
    }

    /**
     * The baseline's spelling if it has the group, else the first environment that does.
     *
     * @param  array<string, array<int, string>>  $spellings
     */
    private function displayName(string $lowerCased, array $spellings, int $baselineIndex): string
    {
        $byEnvironment = $spellings[$lowerCased] ?? null;

        if ($byEnvironment === null) {
            return $lowerCased;
        }

        return $byEnvironment[$baselineIndex] ?? $byEnvironment[array_key_first($byEnvironment)];
    }

    /**
     * @param  array<string, array<int, string>>  $spellings
     */
    private function spellingDiffers(string $lowerCased, array $spellings): bool
    {
        return count(array_unique($spellings[$lowerCased] ?? [])) > 1;
    }
}
