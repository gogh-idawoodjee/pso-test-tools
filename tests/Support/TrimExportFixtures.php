<?php

use App\Support\TrimExport\Segment;

/** Writes $contents to a temporary file and returns its path. */
function trimFixtureFile(string $contents, string $extension = 'xml'): string
{
    $path = tempnam(sys_get_temp_dir(), 'trim_').'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

/** @param iterable<Segment> $segments */
function joinSegments(iterable $segments): string
{
    $bytes = '';
    foreach ($segments as $segment) {
        $bytes .= $segment->prefix.$segment->body;
    }

    return $bytes;
}

/**
 * @param  iterable<Segment>  $segments
 * @return list<string> "type table" for each segment
 */
function segmentShape(iterable $segments): array
{
    $shape = [];
    foreach ($segments as $segment) {
        $shape[] = trim($segment->type.' '.$segment->table);
    }

    return $shape;
}
