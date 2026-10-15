<?php

namespace App\Support\ScheduleData;

interface ScheduleDataRewriter
{
    /**
     * Writes a modified copy of the schedule data file: optionally with every
     * Input_Reference dataset_id replaced, and optionally with every existing
     * Broadcast and Broadcast_Parameter row replaced by the injected one.
     * The source file is never modified. Streams; memory does not grow with
     * the file.
     *
     * @throws \RuntimeException when the file is not schedule data or cannot be written
     */
    public function rewrite(
        string $sourcePath,
        string $destinationPath,
        ?string $datasetIdOverride,
        ?BroadcastInjection $injection,
    ): RewriteReport;
}
