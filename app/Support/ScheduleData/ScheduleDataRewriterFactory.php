<?php

namespace App\Support\ScheduleData;

use InvalidArgumentException;

class ScheduleDataRewriterFactory
{
    /**
     * @param  string  $format  `xml` or `json`, as derived from the file extension
     */
    public function forFormat(string $format): ScheduleDataRewriter
    {
        return match ($format) {
            'xml' => app(XmlScheduleDataRewriter::class),
            'json' => app(JsonScheduleDataRewriter::class),
            default => throw new InvalidArgumentException("No schedule data rewriter for the '{$format}' format."),
        };
    }
}
