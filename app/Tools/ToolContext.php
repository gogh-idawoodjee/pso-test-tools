<?php

namespace App\Tools;

use App\Models\ToolRun;
use App\Support\DatasetSettings;
use Illuminate\Database\Eloquent\Model;

class ToolContext
{
    public function __construct(
        public ToolRun $run,
        public Model $scope,
        public ?DatasetSettings $settings,
        public string $timeZone,
    ) {}
}
