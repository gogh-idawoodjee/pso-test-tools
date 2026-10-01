<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\Cells;
use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

/**
 * Org-default list layouts. List, entry and polygon ids are GUIDs that differ per
 * environment, so lists are matched on content: the org-default list type and the
 * position of each entry label.
 */
class ListsArea implements Area
{
    public function build(ComparisonContext $context): array
    {
        /** @var list<array<string, list<string>>> $layouts environment index => list type => labels in order */
        $layouts = [];

        foreach (array_keys($context->environments) as $environmentIndex) {
            $layouts[] = $this->layoutsFor($context, $environmentIndex);
        }

        $listTypes = ComparisonContext::unionKeys($layouts);
        usort($listTypes, Cells::compareInteger(...));

        $tab = new ComparisonTab('Lists', '06_Lists.csv', ['OrgDefaultList', 'Position']);

        foreach ($listTypes as $listType) {
            $longest = 0;

            foreach ($layouts as $layout) {
                $longest = max($longest, count($layout[$listType] ?? []));
            }

            for ($position = 0; $position < $longest; $position++) {
                $values = array_map(
                    static fn (array $layout): string => $layout[$listType][$position] ?? Cells::NONE,
                    $layouts,
                );

                $tab->addRow(['List type '.$listType, (string) ($position + 1)], $values);
            }
        }

        return [$tab];
    }

    /**
     * @return array<string, list<string>>
     */
    private function layoutsFor(ComparisonContext $context, int $environmentIndex): array
    {
        $labelsByEntryId = [];

        foreach ($context->rows($environmentIndex, 'Entry') as $row) {
            $labelsByEntryId[$row['id'] ?? ''] = $row['label'] ?? null;
        }

        $entriesByList = [];

        foreach ($context->rows($environmentIndex, 'List_Entry') as $row) {
            $entriesByList[$row['list_id'] ?? ''][] = $row;
        }

        $layout = [];

        foreach ($context->rows($environmentIndex, 'Organisation_List') as $organisationList) {
            $entries = $entriesByList[$organisationList['list_id'] ?? ''] ?? [];

            usort($entries, static fn (array $left, array $right): int => Cells::compareInteger($left['sequence'] ?? '', $right['sequence'] ?? ''));

            $layout[$organisationList['list_type_id'] ?? ''] = array_map(
                static fn (array $entry): string => Cells::format($labelsByEntryId[$entry['entry_id'] ?? ''] ?? null),
                $entries,
            );
        }

        return $layout;
    }
}
