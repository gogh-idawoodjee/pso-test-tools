<?php

use App\Enums\Tool;

it('gives every tool a one-line prompt without em dashes', function () {
    foreach (Tool::cases() as $tool) {
        $question = $tool->question();
        expect($question)->toBeString()->not->toBe('')
            ->and(str_contains($question, "\n"))->toBeFalse()
            ->and(str_contains($question, "\u{2014}"))->toBeFalse();
    }
});
