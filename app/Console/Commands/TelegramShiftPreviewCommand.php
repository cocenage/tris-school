<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramShiftPreviewBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class TelegramShiftPreviewCommand extends Command
{
    protected $signature = 'telegram:shift-preview {--date= : Required YYYY-MM-DD date in Europe/Rome} {--json}';

    protected $description = 'Preview district shift duties and existing evidence-backed results without sending';

    public function handle(TelegramShiftPreviewBuilder $builder, TelegramDigestFormatter $formatter): int
    {
        $value = (string) $this->option('date');
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'Europe/Rome');
            if ($date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException;
            }
        } catch (Throwable) {
            $this->error('Date must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }

        try {
            $preview = $builder->build($date);
        } catch (Throwable) {
            $this->error('Shift duties or operational ledger schema is unavailable.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('ОКС · '.$preview['date'].' · Europe/Rome');
        if ($preview['districts'] === []) {
            $this->warn('Активные районы не настроены.');
        }
        foreach ($preview['districts'] as $district) {
            $this->line($district['label'].' · Дежурный: '.($district['duty']['name'] ?? 'нет подтверждённого назначения'));
            $this->line($formatter->eveningIntelligence($district['report']));
            foreach ($district['situations'] as $situation) {
                $this->line('Ситуация: '.($situation['summary'] ?? $situation['event_key']));
                $this->line('Ответственный: '.($situation['assignee']['name'] ?? 'не назначен').' · '.$situation['assignment_status']);
                $this->line('Решение: '.($situation['decision'] ?: 'ожидается'));
                if ($situation['source_status'] === 'unavailable') {
                    $this->warn('Источник ситуации недоступен для выбранного дня и района.');
                }
            }
        }
        $this->line('Read-only preview. Telegram actions = 0. Mutations = 0.');

        return self::SUCCESS;
    }
}
