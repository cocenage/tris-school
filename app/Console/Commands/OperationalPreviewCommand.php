<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalContextBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class OperationalPreviewCommand extends Command
{
    protected $signature = 'operational:preview {--date=} {--json}';

    protected $description = 'Preview a read-only operational context without AI or Telegram delivery';

    public function handle(OperationalContextBuilder $builder): int
    {
        $date = $this->option('date')
            ? Carbon::parse(
                $this->option('date'),
                config('app.timezone', 'Europe/Rome'),
            )->startOfDay()
            : now(config('app.timezone', 'Europe/Rome'))->startOfDay();

        $context = $builder->build($date);

        if ($this->option('json')) {
            $this->line(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('## День');
        $this->line('Дата: ' . $context['date']);
        $this->line('Часовой пояс: ' . $context['timezone']);

        $shift = $context['staff']['shift'] ?? [];
        $this->line(sprintf(
            'Смена: %s/%s работают, отсутствуют: %s, статус: %s',
            $shift['working'] ?? 0,
            $shift['total'] ?? 0,
            $shift['not_working'] ?? 0,
            $shift['label'] ?? 'нет данных',
        ));

        $this->line('Работают: ' . $this->names($context['staff']['working'] ?? []));
        $this->line('Отсутствуют: ' . $this->names($context['staff']['not_working'] ?? [], true));
        $this->line('События календаря: ' . count($context['calendar']['events'] ?? []));

        $this->newLine();
        $this->line('## Риски');

        if (empty($context['risks'])) {
            $this->line('Явные риски по доступным данным не обнаружены.');
        } else {
            foreach ($context['risks'] as $risk) {
                $suffix = isset($risk['impact']) ? ' (влияние: ' . $risk['impact'] . ')' : '';
                $this->line(sprintf('- [%s] %s%s', strtoupper((string) $risk['level']), $risk['message'], $suffix));
            }
        }

        $this->newLine();
        $this->line('## Telegram сигналы');
        $telegram = $context['telegram'];
        $this->line(sprintf(
            'Сообщений: %s, чатов: %s, тем: %s, авторов: %s, вложений: %s',
            $telegram['messages'] ?? 0,
            $telegram['chats'] ?? 0,
            $telegram['topics'] ?? 0,
            $telegram['authors'] ?? 0,
            $telegram['attachments'] ?? 0,
        ));

        foreach ($telegram['signals'] ?? [] as $signal) {
            $this->line(($signal['label'] ?? 'Сигнал') . ': ' . ($signal['count'] ?? 0));
        }

        $this->newLine();
        $this->line('## Качество данных');

        foreach ($context['data_quality'] as $source => $quality) {
            $reason = isset($quality['reason']) ? ' — ' . $quality['reason'] : '';
            $this->line(sprintf('- %s: %s%s', $source, $quality['status'] ?? 'unknown', $reason));
        }

        $this->newLine();
        $this->line('Режим: только чтение. AI не вызывается, Telegram не отправляется, данные не изменяются.');

        return self::SUCCESS;
    }

    protected function names(array $users, bool $withReason = false): string
    {
        if (empty($users)) {
            return 'нет';
        }

        return collect($users)
            ->map(function (array $user) use ($withReason) {
                $name = $user['name'] ?: 'Без имени';

                return $withReason && filled($user['reason'] ?? null)
                    ? $name . ' — ' . $user['reason']
                    : $name;
            })
            ->implode(', ');
    }
}
