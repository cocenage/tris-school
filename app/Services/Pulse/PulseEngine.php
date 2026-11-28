<?php

namespace App\Services\Pulse;

use Carbon\CarbonImmutable;

final class PulseEngine
{
    /** @param list<PulseFact> $facts @return list<PulseFact> */
    public function select(array $facts, CarbonImmutable $now): array
    {
        $active = array_filter($facts, static fn (PulseFact $fact): bool =>
            $fact->expiresAt === null || $fact->expiresAt->gt($now)
        );
        usort($active, static fn (PulseFact $a, PulseFact $b): int =>
            [$a->priority, $a->id] <=> [$b->priority, $b->id]
        );

        $selected = [];
        $seen = [];
        foreach ($active as $fact) {
            if (isset($seen[$fact->kind])) {
                continue;
            }
            $seen[$fact->kind] = true;
            $selected[] = $fact;
            if (count($selected) === 3) {
                break;
            }
        }

        return $selected;
    }
}
