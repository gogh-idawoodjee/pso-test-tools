<?php

namespace App\Support\SysCompare;

use App\Support\SysCompare\Areas\Area;
use App\Support\SysCompare\Areas\ExceptionTypesArea;
use App\Support\SysCompare\Areas\GroupsArea;
use App\Support\SysCompare\Areas\ListsArea;
use App\Support\SysCompare\Areas\OrgPermissionsArea;
use App\Support\SysCompare\Areas\ParametersArea;
use App\Support\SysCompare\Areas\ProfilesAndOtherArea;
use App\Support\SysCompare\Areas\TravelArea;
use App\Support\SysCompare\Exceptions\InvalidComparison;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The comparison engine: compare(environments, baseline) -> ComparisonResult.
 * Pure: no web, file-system or network access. Renderers turn the result into
 * HTML, CSV or Excel.
 */
class Comparer
{
    /**
     * @return list<class-string<Area>>
     */
    protected function areas(): array
    {
        return [
            ParametersArea::class,
            ExceptionTypesArea::class,
            GroupsArea::class,
            OrgPermissionsArea::class,
            ListsArea::class,
            TravelArea::class,
            ProfilesAndOtherArea::class,
        ];
    }

    /**
     * @param  list<SysEnvironment>  $environments  in the order they should appear
     */
    public function compare(
        array $environments,
        string $baselineName,
        ?ParamDefinitions $definitions = null,
        ?DateTimeImmutable $now = null,
    ): ComparisonResult {
        $baselineIndex = $this->validate($environments, $baselineName);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $context = new ComparisonContext($environments, $baselineIndex, $definitions ?? ParamDefinitions::builtIn());

        $tabs = [];

        foreach ($this->areas() as $areaClass) {
            array_push($tabs, ...app($areaClass)->build($context));
        }

        $tabs = $this->inReportOrder($tabs);

        return new ComparisonResult(
            environments: array_map(
                static fn (SysEnvironment $environment): EnvironmentSummary => new EnvironmentSummary(
                    $environment->name,
                    $environment->sysFile->fileName,
                    $environment->sysFile->sizeBytes,
                ),
                $environments,
            ),
            baselineIndex: $baselineIndex,
            tabs: $tabs,
            tableCounts: $this->tableCounts($environments),
            tally: $this->tally($tabs, $context),
            versions: app(VersionAnalyzer::class)->analyze($environments, $now),
            apiKeyValuesDiffer: $context->apiKeyValuesDiffer(),
            definitionTemplate: $this->definitionTemplate($tabs, $context),
            quickRead: $this->quickRead($tabs, $context),
            generatedAt: $now,
        );
    }

    /**
     * @param  list<SysEnvironment>  $environments
     */
    private function validate(array $environments, string $baselineName): int
    {
        if (count($environments) < 2) {
            throw new InvalidComparison('At least two environments are needed to compare.');
        }

        $baselineIndex = null;
        $seenNames = [];

        foreach ($environments as $index => $environment) {
            if (trim($environment->name) === '') {
                throw new InvalidComparison('Every environment needs a name.');
            }

            if (isset($seenNames[$environment->name])) {
                throw new InvalidComparison("The name \"{$environment->name}\" is used more than once. Names must be unique.");
            }

            $seenNames[$environment->name] = true;

            if ($environment->name === $baselineName) {
                $baselineIndex = $index;
            }
        }

        if ($baselineIndex === null) {
            throw new InvalidComparison("The baseline \"{$baselineName}\" is not one of the environments.");
        }

        return $baselineIndex;
    }

    /**
     * Areas are built in dependency order, but reported in the order of the reference script.
     *
     * @param  list<ComparisonTab>  $tabs
     * @return list<ComparisonTab>
     */
    private function inReportOrder(array $tabs): array
    {
        $order = ['Parameters', 'Exception Types', 'Group Permissions', 'Groups', 'Org Permissions', 'Lists', 'Travel', 'Profiles & Other'];

        usort($tabs, static fn (ComparisonTab $left, ComparisonTab $right): int => array_search($left->title, $order, true) <=> array_search($right->title, $order, true));

        return $tabs;
    }

    /**
     * @param  list<SysEnvironment>  $environments
     * @return list<TableCountRow>
     */
    private function tableCounts(array $environments): array
    {
        $tables = [];

        foreach ($environments as $environment) {
            foreach (array_keys($environment->sysFile->tableCounts) as $table) {
                $tables[(string) $table] = true;
            }
        }

        $tables = array_map('strval', array_keys($tables));
        usort($tables, Cells::compareText(...));

        return array_map(
            static fn (string $table): TableCountRow => new TableCountRow(
                $table,
                array_map(static fn (SysEnvironment $environment): int => $environment->sysFile->tableCounts[$table] ?? 0, $environments),
                TableScopes::for($table),
            ),
            $tables,
        );
    }

    /**
     * @param  list<ComparisonTab>  $tabs
     * @return list<AreaTally>
     */
    private function tally(array $tabs, ComparisonContext $context): array
    {
        $names = $context->names();

        return array_map(static function (ComparisonTab $tab) use ($names, $context): AreaTally {
            $differences = array_fill_keys($names, 0);

            foreach ($tab->rows() as $row) {
                foreach ($names as $index => $name) {
                    if ($row->differsFromBaseline($index, $context->baselineIndex)) {
                        $differences[$name]++;
                    }
                }
            }

            return new AreaTally($tab->title, $tab->rowCount(), $differences, count($tab->differingRows()));
        }, $tabs);
    }

    /**
     * Parameters with no specific definition, with the name-pattern hint (if any) as a starting point.
     *
     * @param  list<ComparisonTab>  $tabs
     * @return array<string, string>
     */
    private function definitionTemplate(array $tabs, ComparisonContext $context): array
    {
        $template = [];

        foreach ($this->tab($tabs, 'Parameters')->rows() as $row) {
            $parameter = $row->keyValues[1];

            if (! $context->definitions->hasSpecific($parameter) && ! isset($template[$parameter])) {
                $template[$parameter] = $context->definitions->find($parameter)?->text ?? '';
            }
        }

        uksort($template, Cells::compareText(...));

        return $template;
    }

    /**
     * @param  list<ComparisonTab>  $tabs
     */
    private function quickRead(array $tabs, ComparisonContext $context): QuickRead
    {
        $names = $context->names();

        $differingParameters = $this->tab($tabs, 'Parameters')->differingRows();
        $presenceDifferences = 0;
        $withoutDefinition = [];

        foreach ($differingParameters as $row) {
            if (in_array(Cells::ABSENT, $row->cellValues, true)) {
                $presenceDifferences++;
            }

            if (($row->extra['Definition'] ?? '') === '') {
                $withoutDefinition[$row->keyValues[1]] = true;
            }
        }

        $withoutDefinition = array_map('strval', array_keys($withoutDefinition));
        usort($withoutDefinition, Cells::compareText(...));

        $groupsNotEverywhere = [];

        foreach ($this->tab($tabs, 'Groups')->rows() as $row) {
            if ($row->keyValues[1] !== 'Present as') {
                continue;
            }

            $missingIn = [];

            foreach ($row->cellValues as $index => $value) {
                if ($value === Cells::ABSENT) {
                    $missingIn[] = $names[$index];
                }
            }

            if ($missingIn !== []) {
                $groupsNotEverywhere[] = $row->keyValues[0].' (missing in '.implode(', ', $missingIn).')';
            }
        }

        $onlyInOne = array_fill_keys($names, 0);

        foreach ($this->tab($tabs, 'Exception Types')->rows() as $row) {
            $present = array_keys(array_filter($row->cellValues, static fn (string $value): bool => $value !== Cells::ABSENT));

            if (count($present) === 1) {
                $onlyInOne[$names[$present[0]]]++;
            }
        }

        return new QuickRead(
            differingParameterRows: count($differingParameters),
            parametersWithDifferentValues: count($differingParameters) - $presenceDifferences,
            parametersPresentInSomeOnly: $presenceDifferences,
            differingParametersWithoutDefinition: $withoutDefinition,
            groupsNotPresentEverywhere: $groupsNotEverywhere,
            exceptionTypesInOnlyOneEnvironment: $onlyInOne,
        );
    }

    /**
     * @param  list<ComparisonTab>  $tabs
     */
    private function tab(array $tabs, string $title): ComparisonTab
    {
        foreach ($tabs as $tab) {
            if ($tab->title === $title) {
                return $tab;
            }
        }

        throw new InvalidComparison("The {$title} area was not built.");
    }
}
