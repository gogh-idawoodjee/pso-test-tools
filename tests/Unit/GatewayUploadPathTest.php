<?php

use App\Support\GatewayUploadPath;

it('allows the paths the gateway upload field actually produces', function (string $path) {
    expect(GatewayUploadPath::isAllowed($path))->toBeTrue();
})->with([
    'ulid json' => ['gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D.json'],
    'ulid xml' => ['gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D.xml'],
    'ulid zip' => ['gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D.zip'],
    'uppercase extension' => ['gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D.JSON'],
    'plain name' => ['gateway-uploads/schedule.json'],
]);

it('rejects anything else', function (?string $path) {
    expect(GatewayUploadPath::isAllowed($path))->toBeFalse();
})->with([
    'null' => [null],
    'empty' => [''],
    'another directory' => ['livewire-tmp/other.json'],
    'traversal' => ['gateway-uploads/../livewire-tmp/other.json'],
    'traversal in the file name' => ['gateway-uploads/..%2fother.json'],
    'nested' => ['gateway-uploads/nested/other.json'],
    'no directory' => ['other.json'],
    'absolute' => ['/etc/passwd'],
    'disallowed extension' => ['gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D.php'],
    'no extension' => ['gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D'],
    'double extension' => ['gateway-uploads/01JQZ8YQ.php.json'],
    'leading slash' => ['/gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D.json'],
    'trailing newline' => ["gateway-uploads/01JQZ8YQ6TQ2N3XF9C7K5A4B2D.json\n"],
    'prefix lookalike' => ['not-gateway-uploads/01JQZ8YQ.json'],
    'wrapped in a scheme' => ['phar://gateway-uploads/01JQZ8YQ.json'],
]);
