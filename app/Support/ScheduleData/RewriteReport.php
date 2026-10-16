<?php

namespace App\Support\ScheduleData;

readonly class RewriteReport
{
    /**
     * @param  ?string  $format  `xml` (an XML schedule data root), `ScheduleData` or `dsScheduleData` (the JSON wrapper), or null when no wrapper was found
     * @param  bool  $wrapperFound  false when the file had no schedule data wrapper and was copied unchanged
     * @param  list<string>  $datasetIds  distinct original Input_Reference dataset_id values
     * @param  int  $inputReferencesMarked  root-level Input_Reference rows given the identity's user_id and description
     * @param  int  $identityFieldsReplaced  of those values, how many replaced one already in the file (the rest were added)
     */
    public function __construct(
        public ?string $format,
        public bool $wrapperFound,
        public array $datasetIds,
        public bool $datasetIdChanged,
        public int $strippedBroadcasts,
        public int $strippedBroadcastParameters,
        public int $bytesWritten,
        public int $inputReferencesMarked = 0,
        public int $identityFieldsReplaced = 0,
    ) {}
}
