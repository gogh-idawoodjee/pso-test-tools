<?php

namespace App\Support\ScheduleData;

readonly class RewriteReport
{
    /**
     * @param  ?string  $format  `xml` (an XML schedule data root), `ScheduleData` or `dsScheduleData` (the JSON wrapper), or null when no wrapper was found
     * @param  bool  $wrapperFound  false when the file had no schedule data wrapper and was copied unchanged
     * @param  list<string>  $datasetIds  distinct original Input_Reference dataset_id values
     */
    public function __construct(
        public ?string $format,
        public bool $wrapperFound,
        public array $datasetIds,
        public bool $datasetIdChanged,
        public int $strippedBroadcasts,
        public int $strippedBroadcastParameters,
        public int $bytesWritten,
    ) {}
}
