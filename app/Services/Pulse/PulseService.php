<?php

namespace App\Services\Pulse;

use App\Models\User;
use Carbon\CarbonImmutable;

class PulseService
{
    public function __construct(
        private PersonalContextProvider $provider,
        private PulseEngine $engine,
        private PulseComposer $composer,
    ) {}

    public function snapshot(User $user, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone('Europe/Rome');
        $facts = $this->engine->select($this->provider->facts($user, $now), $now);
        $segments = $this->composer->compose((string) $user->name, $facts, $now);
        $validUntil = $now->addSeconds(30);
        foreach ($facts as $fact) {
            if ($fact->expiresAt !== null && $fact->expiresAt->lt($validUntil)) {
                $validUntil = $fact->expiresAt;
            }
        }
        foreach ([5, 12, 18, 23, 24] as $hour) {
            $boundary = $hour === 24 ? $now->startOfDay()->addDay() : $now->startOfDay()->setHour($hour);
            if ($boundary->gt($now) && $boundary->lt($validUntil)) {
                $validUntil = $boundary;
            }
        }

        return [
            'schema_version' => 1,
            'version' => hash('sha256', json_encode([$now->toDateString(), $segments], JSON_THROW_ON_ERROR)),
            'generated_at' => $now->toIso8601String(),
            'valid_until' => $validUntil->toIso8601String(),
            'date' => $now->toDateString(),
            'timezone' => 'Europe/Rome',
            'locale' => 'ru',
            'state' => match (true) {
                ($facts[0]->priority ?? 4) === 0 => 'critical',
                ($facts[0]->priority ?? 4) === 1 => 'attention',
                $facts !== [] => 'active',
                default => 'calm',
            },
            'segments' => $segments,
        ];
    }
}
