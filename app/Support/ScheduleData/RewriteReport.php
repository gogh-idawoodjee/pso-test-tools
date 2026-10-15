<?php

namespace App\Support\ScheduleData;

readonly class RewriteReport
{
    /**
     * @param  string  $format  `xml`, `ScheduleData` or `dsScheduleData`
     * @param  list<string>  $datasetIds  distinct original Input_Reference dataset_id values
     */
    public function __construct(
        public string $format,
        public array $datasetIds,
        public bool $datasetIdChanged,
        public int $strippedBroadcasts,
        public int $strippedBroadcastParameters,
        public int $bytesWritten,
    ) {}
}
