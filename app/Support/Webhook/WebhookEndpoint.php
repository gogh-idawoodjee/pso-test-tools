<?php

namespace App\Support\Webhook;

readonly class WebhookEndpoint
{
    public function __construct(
        public string $url,
        public string $viewUrl,
        public string $uuid,
        public ?string $alias,
        public bool $created,
    ) {}
}
