<?php

namespace App\Support\SysCompare\Areas;

use App\Support\SysCompare\ComparisonContext;
use App\Support\SysCompare\ComparisonTab;

interface Area
{
    /**
     * @return list<ComparisonTab>
     */
    public function build(ComparisonContext $context): array;
}
