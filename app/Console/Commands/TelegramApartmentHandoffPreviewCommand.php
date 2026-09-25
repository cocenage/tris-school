<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApartmentShiftHandoffBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class TelegramApartmentHandoffPreviewCommand extends Command
{
    protected $signature = 'telegram:apartment-handoff-preview
        {--date= : Calendar date in the application timezone}
        {--json : Emit machine-readable handoff previews}';

    protected $description = 'Preview next-shift handoffs from existing OI attention items without sending';

    public function handle(TelegramApartmentShiftHandoffBuilder $builder): int
    {
        $date = $this->dateOption();
        if ($date === null) {
            return self::FAILURE;
        }

        try {
            $preview = $builder->build($date);
        } catch (Throwable) {
            $this->error('Operational event ledger or apartment-topic mappings are unavailable.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($preview['handoffs'] === [] && $preview['skipped'] === []) {
            $this->line('Нет активных событий, требующих внимания. Telegram actions: 0. Ledger mutations: 0.');

            return self::SUCCESS;
        }

        foreach ($preview['handoffs'] as $handoff) {
            $this->newLine();
            $this->line($handoff['apartment_name']);

            if ($handoff['mapping_status'] !== 'ready') {
                $this->warn('Пропуск: '.($handoff['mapping_status'] === 'mapping_missing'
                    ? 'не найдена включённая связь квартиры с Telegram topic.'
                    : 'найдено несколько включённых Telegram topics квартиры; назначение неоднозначно.'));

                continue;
            }

            $this->line(sprintf(
                'Назначение: %s (chat %s / topic %s)',
                $handoff['destination_topic_title'],
                $handoff['destination_chat_id'],
                $handoff['destination_thread_id'],
            ));
            foreach (explode("\n", $handoff['message']) as $line) {
                $this->line($line);
            }
        }

        foreach ($preview['skipped'] as $skipped) {
            $this->warn('Событие без определённой квартиры: пропуск, назначение не угадывается.');
        }

        $this->newLine();
        $this->line('Предпросмотр: Telegram actions = 0; ledger mutations = 0.');

        return self::SUCCESS;
    }

    private function dateOption(): ?Carbon
    {
        if (! filled($this->option('date'))) {
            $this->error('Date is required and must use YYYY-MM-DD.');

            return null;
        }

        $value = (string) $this->option('date');
        $timezone = config('app.timezone', 'Europe/Rome');

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            $this->error('Date must use YYYY-MM-DD.');

            return null;
        }

        if ($date->format('Y-m-d') !== $value) {
            $this->error('Date must be a valid YYYY-MM-DD date.');

            return null;
        }

        return $date;
    }
}
