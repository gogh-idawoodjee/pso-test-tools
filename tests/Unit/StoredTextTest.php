<?php

use App\Support\GatewayUpload\StoredText;

it('leaves text within the limit untouched', function () {
    expect(StoredText::cap('short', 10))->toBe('short');
    expect(StoredText::cap(str_repeat('a', 10), 10))->toBe(str_repeat('a', 10));
});

it('cuts on a character boundary and keeps the marker inside the limit', function () {
    $capped = StoredText::cap(str_repeat('é', 100), 51);

    expect(strlen($capped))->toBeLessThanOrEqual(51);
    expect($capped)->toEndWith(StoredText::TRUNCATED_MARKER);
    expect(mb_check_encoding($capped, 'UTF-8'))->toBeTrue();
});
