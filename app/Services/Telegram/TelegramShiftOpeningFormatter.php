<?php

namespace App\Services\Telegram;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TelegramShiftOpeningFormatter
{
    public function __construct(private readonly TelegramRichMessageBuilder $richMessages) {}

    public function format(array $preview): array
    {
        $title = 'ОКС · Открытие смены';
        $date = $preview['date'].' · '.$preview['timezone'];
        $districts = collect($preview['districts']);
        $attention = $districts->flatMap(fn (array $district): array => $this->situations($district)
            ->map(fn (string $situation): string => $district['label'].' · '.$situation)->all());

        $rich = ['# '.$title, $this->richMessages->escapeRichText($date), '', '## Дежурные по районам'];
        $fallback = ['<b>'.$title.'</b>', e($date), '', '<b>Дежурные по районам</b>'];

        foreach ($districts as $district) {
            $line = $district['label'].' · '.$this->duty($district);
            $rich[] = '- '.$this->richMessages->escapeRichText($line);
            $fallback[] = '• '.e($line);
        }

        if ($districts->isEmpty()) {
            $rich[] = 'Активные районы не настроены.';
            $fallback[] = 'Активные районы не настроены.';
        }

        $rich[] = '';
        $fallback[] = '';
        $rich[] = 'Расписание уборок недоступно.';
        $fallback[] = 'Расписание уборок недоступно.';

        if ($attention->isNotEmpty()) {
            $rich = [...$rich, '', '## Требует внимания · '.$attention->count()];
            $fallback = [...$fallback, '', '<b>Требует внимания · '.$attention->count().'</b>'];
            foreach ($attention->take(5) as $line) {
                $rich[] = '- '.$this->richMessages->escapeRichText($line);
                $fallback[] = '• '.e($line);
            }
            if ($attention->count() > 5) {
                $fallback[] = 'Ещё ситуаций · '.($attention->count() - 5);
            }
        }

        foreach ($districts as $district) {
            $situations = $this->situations($district);
            $rich[] = '';
            $rich[] = '<details><summary>'.$this->richMessages->escapeRichText('Подробнее · '.$district['label']).'</summary>';
            $rich[] = '';
            $rich[] = '**Дежурство** · '.$this->richMessages->escapeRichText($this->duty($district));
            foreach ($situations->take(10) as $situation) {
                $rich[] = '- '.$this->richMessages->escapeRichText($situation);
            }
            if ($situations->count() > 10) {
                $rich[] = 'Ещё ситуаций · '.($situations->count() - 10);
            }
            $rich[] = '</details>';
        }

        return [
            'rich_message' => $this->richMessages->markdownDocument(implode("\n", $rich)),
            'fallback_html' => implode("\n", $fallback),
        ];
    }

    private function duty(array $district): string
    {
        return match ($district['duty_status']) {
            'assigned' => Str::limit($district['duty']['name'], 100),
            'unavailable' => 'Назначенный дежурный недоступен',
            default => 'Дежурный не назначен',
        };
    }

    private function situations(array $district): Collection
    {
        return collect($district['situations'])
            ->filter(fn (array $situation): bool => $situation['source_status'] === 'matched'
                && in_array($situation['ledger_status'], ['open', 'reopened'], true)
                && filled($situation['summary']))
            ->map(function (array $situation): string {
                $assignee = match ($situation['assignment_status']) {
                    'assigned' => Str::limit($situation['assignee']['name'], 100),
                    'unavailable' => 'назначенный сотрудник недоступен',
                    default => 'не назначен',
                };

                return Str::limit($situation['summary'], 200).' · Ответственный — '.$assignee;
            });
    }
}
