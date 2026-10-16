<?php

namespace App\Support\ScheduleData;

interface ScheduleDataRewriter
{
    /**
     * Writes a modified copy of the schedule data file: optionally with every
     * Input_Reference dataset_id replaced, optionally with the injected
     * Broadcast added, and with every existing Broadcast and
     * Broadcast_Parameter row removed when $stripExisting is true or a
     * Broadcast is injected (so the file never carries two), and, when an
     * identity is given, with `user_id` and `description` set in every
     * root-level Input_Reference (replaced where present, added where not).
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
        bool $stripExisting = true,
        ?InputReferenceIdentity $identity = null,
    ): RewriteReport;
}
