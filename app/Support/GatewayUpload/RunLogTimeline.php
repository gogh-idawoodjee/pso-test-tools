<?php

namespace App\Support\GatewayUpload;

use App\Enums\GatewayUploadLogLevel;

/**
 * Groups the flat run log into timeline nodes: each step line opens a node
 * and the sub, warn, error and ok lines after it belong to it until the
 * next step. Lines before the first step form a leading node.
 */
final class RunLogTimeline
{
    /**
     * @param  array<int, array{level: string, text: string, at: string}>  $lines
     * @param  string  $leadingTitle  title of the node for lines before the first step (the upload's status label)
     * @param  bool  $running  whether the upload is still in progress: the last node is then the current one
     * @return list<RunLogNode>
     */
    public static function group(array $lines, string $leadingTitle, bool $running): array
    {
        /** @var list<array{title: string, at: ?string, details: list<array{level: string, text: string, at: string}>}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            if ($line['level'] === GatewayUploadLogLevel::STEP->value) {
                $groups[] = ['title' => self::title($line['text']), 'at' => $line['at'], 'details' => []];

                continue;
            }

            if ($groups === []) {
                $groups[] = ['title' => $leadingTitle, 'at' => $line['at'], 'details' => []];
            }

            $groups[array_key_last($groups)]['details'][] = $line;
        }

        $lastIndex = array_key_last($groups);
        $nodes = [];

        foreach ($groups as $index => $group) {
            $levels = array_column($group['details'], 'level');

            $nodes[] = new RunLogNode(
                title: $group['title'],
                at: $group['at'],
                details: $group['details'],
                hasWarn: in_array(GatewayUploadLogLevel::WARN->value, $levels, true),
                hasError: in_array(GatewayUploadLogLevel::ERROR->value, $levels, true),
                hasOk: in_array(GatewayUploadLogLevel::OK->value, $levels, true),
                isCurrent: $running && $index === $lastIndex,
            );
        }

        return $nodes;
    }

    /**
     * The step text without the script's "=== ... ===" banner, which reads
     * as noise in a timeline title.
     */
    private static function title(string $text): string
    {
        $title = trim(trim($text), '= ');

        return $title === '' ? trim($text) : $title;
    }
}
