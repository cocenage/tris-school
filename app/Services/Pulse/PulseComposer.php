<?php

namespace App\Services\Pulse;

use Carbon\CarbonImmutable;

final class PulseComposer
{
    /** @param list<PulseFact> $facts */
    public function compose(string $name, array $facts, CarbonImmutable $now): array
    {
        $greeting = match (true) {
            $now->hour >= 5 && $now->hour < 12 => 'Доброе утро',
            $now->hour >= 12 && $now->hour < 18 => 'Добрый день',
            $now->hour >= 18 && $now->hour < 23 => 'Добрый вечер',
            default => 'Привет',
        };
        $name = trim($name);
        $segments = [$this->segment('greeting', $name === '' ? $greeting.'!' : $greeting.', ')];
        if ($name !== '') {
            $segments[] = $this->segment('name', $name.'!', true, '/profile');
        }
        foreach ($facts as $fact) {
            $segments[] = $this->segment($fact->id.':prefix', ' '.$fact->prefix);
            $segments[] = $this->segment($fact->id.':accent', $fact->accent, true, $fact->url, $fact->icon);
            if ($fact->suffix !== '') {
                $segments[] = $this->segment($fact->id.':suffix', $fact->suffix);
            }
        }
        if ($facts === []) {
            $segments[] = $this->segment('empty', ' Пока нет актуальной информации.');
        }

        return $segments;
    }

    private function segment(string $id, string $text, bool $accent = false, ?string $url = null, ?string $icon = null): array
    {
        return [
            'id' => $id,
            'text' => $text,
            'accent' => $accent,
            'icon' => $icon,
            'action' => $url === null ? null : ['type' => 'navigate', 'url' => $url],
        ];
    }

    public static function plural(int $count, string $one, string $few, string $many): string
    {
        if ($count % 100 >= 11 && $count % 100 <= 14) {
            return $many;
        }

        return match ($count % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }
}
