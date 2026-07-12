<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use App\Services\Weather\MilanWeatherService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilityDigestCommand extends Command
{
    protected $signature = 'mobility:digest {--date=} {--dry-run}';

    protected $description = 'Send daily shift assistant digest to Telegram forum topics';

    protected array $greetings = [
        '☀️ Доброе утро',
        '🌤 Хорошего дня',
        '☕ Утренний дайджест',
        '👋 Всем привет',
        '🌞 Новый день начинается',
        '🚀 Поехали работать',
        '✨ Хорошего начала дня',
        '🌅 Утренние новости',
    ];

    protected array $endings = [
        'Хорошей смены ☀️',
        'Удачного дня ✨',
        'Отличной смены 🚀',
        'Легкого рабочего дня 🌤',
        'Пусть день пройдет спокойно 🤝',
    ];

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();

        $alerts = MobilityAlert::query()
            ->whereDate('starts_at', $date)
            ->whereIn('risk', ['critical', 'high', 'medium'])
            ->orderByRaw("
                CASE risk
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    ELSE 4
                END
            ")
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (MobilityAlert $alert) => $this->shouldShowInWorkerDigest($alert))
            ->values();

        $message = $this->buildMessage($date, $alerts);

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('===== DRY RUN MOBILITY DIGEST =====');
            $this->line($message);
            $this->line('===================================');
            $this->line('');

            return self::SUCCESS;
        }

        $this->sendTelegram($message);

        $this->info('Daily shift digest sent.');

        return self::SUCCESS;
    }

    protected function buildMessage(Carbon $date, $alerts): string
    {
        $weather = app(MilanWeatherService::class)->today();

        $text = '<b>' . Arr::random($this->greetings) . "</b>\n\n";

        $text .= "Сегодня: {$weather['summary']}\n";

        if (! empty($weather['advice'])) {
            $text .= $weather['advice'] . "\n";
        }

        if ($alerts->isNotEmpty()) {
            $text .= "\n🚦 <b>Передвижение</b>\n\n";

            $critical = $alerts->filter(fn (MobilityAlert $alert) => $this->isStrike($alert))->values();
            $other = $alerts->reject(fn (MobilityAlert $alert) => $this->isStrike($alert))->values();

            if ($critical->isNotEmpty()) {
                $text .= "🚨 <b>Важно:</b>\n";

                foreach ($critical->take(5) as $alert) {
                    $text .= $this->workerAlertLine($alert);
                }
            }

            if ($other->isNotEmpty()) {
                if ($critical->isNotEmpty()) {
                    $text .= "\n";
                }

                $text .= "⚠️ <b>Ещё изменения:</b>\n";

                foreach ($other->take(3) as $alert) {
                    $text .= $this->workerAlertLine($alert);
                }
            }
        }

        $text .= "\n" . Arr::random($this->endings);

        return trim($text);
    }

    protected function shouldShowInWorkerDigest(MobilityAlert $alert): bool
    {
        if ($this->isTrash($alert)) {
            return false;
        }

        if ($this->isStrike($alert)) {
            return true;
        }

        $title = mb_strtolower($alert->title);
        $type = mb_strtolower($alert->type ?? '');

        return str_contains($title, 'trenord')
            || str_contains($type, 'train')
            || str_contains($title, 'chiusura')
            || str_contains($title, 'chiude')
            || str_contains($title, 'lavori')
            || str_contains($title, 'cantieri')
            || str_contains($title, 'deviazioni')
            || str_contains($title, 'circolazione')
            || str_contains($title, 'viabilità')
            || str_contains($title, 'manifestazione')
            || str_contains($title, 'maratona');
    }

    protected function isStrike(MobilityAlert $alert): bool
    {
        $title = mb_strtolower($alert->title);
        $description = mb_strtolower($alert->description ?? '');
        $type = mb_strtolower($alert->type ?? '');

        return str_contains($type, 'strike')
            || str_contains($title, 'sciopero')
            || str_contains($description, 'sciopero')
            || str_contains($title, 'strike')
            || str_contains($description, 'strike')
            || str_contains($title, 'забаст')
            || str_contains($description, 'забаст');
    }

    protected function isTrash(MobilityAlert $alert): bool
    {
        $title = mb_strtolower($alert->title);
        $url = mb_strtolower($alert->url ?? '');

        $trash = [
            'metro maps',
            'mappa metro',
            'manifestazione di interesse',
            'manifestazioni di interesse',
            'vendita',
            'affitto',
            'immobili',
            'fibre ottiche',
            'fornitori',
            'impreseefornitori',
            'biglietti',
            'abbonamenti',
            'privacy',
            'cookie',
            'lavora con noi',
            'contatti',
        ];

        foreach ($trash as $keyword) {
            if (str_contains($title, $keyword) || str_contains($url, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function workerAlertLine(MobilityAlert $alert): string
    {
        if ($this->isStrike($alert)) {
            return "• Забастовка общественного транспорта. Лучше заранее проверить маршрут.\n";
        }

        $title = mb_strtolower($alert->title);

        if (str_contains($title, 'trenord')) {
            return "• Возможны изменения поездов Trenord. Проверьте расписание заранее.\n";
        }

        if (
            str_contains($title, 'chiusura') ||
            str_contains($title, 'chiude') ||
            str_contains($title, 'lavori') ||
            str_contains($title, 'cantieri')
        ) {
            return "• Работы или перекрытия. Лучше заложить больше времени.\n";
        }

        if (str_contains($title, 'manifestazione')) {
            return "• Мероприятие или демонстрация. Возможны задержки на дорогах.\n";
        }

        if (str_contains($title, 'maratona')) {
            return "• Марафон или спортивное событие. Возможны перекрытия и пробки.\n";
        }

        return "• " . e($alert->title) . "\n";
    }

    protected function telegramTargets(): array
    {
        $raw = config('services.telegram.mobility_targets');

        if (! $raw) {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->map(function ($item) {
                [$chatId, $threadId] = array_pad(
                    explode(':', $item, 2),
                    2,
                    null
                );

                return [
                    'chat_id' => trim($chatId),
                    'thread_id' => $threadId ? trim($threadId) : null,
                ];
            })
            ->filter(fn ($target) => filled($target['chat_id']))
            ->values()
            ->all();
    }

protected function sendTelegram(string $message): void
{
    $token = config('services.telegram.analytics_bot_token');
    $targets = $this->telegramTargets();

    if (! $token || empty($targets)) {
        Log::warning('Daily shift digest skipped: missing Telegram config', [
            'token_exists' => filled($token),
            'targets_count' => count($targets),
        ]);

        return;
    }

    foreach ($targets as $target) {
        $payload = [
            'chat_id' => $target['chat_id'],
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (! empty($target['thread_id'])) {
            $payload['message_thread_id'] = $target['thread_id'];
        }

        try {
            $response = Http::timeout(30)
                ->retry(3, 2000)
                ->withoutVerifying()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        } catch (\Throwable $e) {
            Log::warning('Daily shift digest telegram connection failed', [
                'chat_id' => $target['chat_id'],
                'thread_id' => $target['thread_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            continue;
        }

        if (! $response->successful()) {
            Log::warning('Daily shift digest telegram failed', [
                'chat_id' => $target['chat_id'],
                'thread_id' => $target['thread_id'] ?? null,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            continue;
        }

        $telegramMessageId = data_get($response->json(), 'result.message_id');

        if ($telegramMessageId) {
            MobilityAlertMessage::create([
                'mobility_alert_id' => null,
                'message_type' => 'worker_digest',
                'chat_id' => (string) $target['chat_id'],
                'thread_id' => $target['thread_id'] ? (string) $target['thread_id'] : null,
                'telegram_message_id' => (string) $telegramMessageId,
                'text' => $message,
                'sent_at' => now(),
            ]);
        }

        usleep(500000);
    }
}
}