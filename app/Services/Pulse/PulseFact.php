<?php

namespace App\Services\Pulse;

use Carbon\CarbonImmutable;

final readonly class PulseFact
{
    public function __construct(
        public string $id,
        public string $kind,
        public int $priority,
        public string $prefix,
        public string $accent,
        public string $suffix = '.',
        public ?string $url = null,
        public ?string $icon = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
