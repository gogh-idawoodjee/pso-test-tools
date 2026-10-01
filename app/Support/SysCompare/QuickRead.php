<?php

namespace App\Support\SysCompare;

/**
 * The handful of facts behind the "Quick read" list in the report.
 */
final readonly class QuickRead
{
    /**
     * @param  list<string>  $differingParametersWithoutDefinition
     * @param  list<string>  $groupsNotPresentEverywhere  "Group (missing in A, B)"
     * @param  array<string, int>  $exceptionTypesInOnlyOneEnvironment  environment name => rows
     */
    public function __construct(
        public int $differingParameterRows,
        public int $parametersWithDifferentValues,
        public int $parametersPresentInSomeOnly,
        public array $differingParametersWithoutDefinition,
        public array $groupsNotPresentEverywhere,
        public array $exceptionTypesInOnlyOneEnvironment,
    ) {}
}
