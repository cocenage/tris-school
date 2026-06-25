<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\Weather\MilanWeatherService;
use App\Models\TelegramTopic;
use Illuminate\Support\Arr;

class MobilityDigestCommand extends Command
{
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
   protected $signature = 'mobility:digest {--date=} {--dry-run}';

    protected $description = 'Send daily shift assistant digest to Telegram forum topics';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();

        $alerts = MobilityAlert::query()
            ->whereDate('starts_at', $date)
            ->whereIn('risk', ['high', 'medium'])
            ->get()
            ->filter(fn (MobilityAlert $alert) => $this->shouldIncludeAlert($alert))
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

    $importantAlerts = $alerts
        ->filter(fn (MobilityAlert $alert) => $this->isReallyImportantAlert($alert))
        ->values();

    if ($importantAlerts->isNotEmpty()) {
        $text .= "\n🚦 <b>Передвижение</b>\n\n";
        $text .= "🚨 <b>Важно:</b>\n";

        foreach ($importantAlerts->take(5) as $alert) {
            $text .= $this->importantAlertLine($alert);
        }

        if ($importantAlerts->count() > 5) {
            $text .= "• и ещё " . ($importantAlerts->count() - 5) . "\n";
        }
    }

    $text .= "\n" . Arr::random($this->endings);

    return trim($text);
}

protected function isReallyImportantAlert(MobilityAlert $alert): bool
{
    $title = mb_strtolower($alert->title);
    $type = mb_strtolower($alert->type ?? '');

    return str_contains($title, 'sciopero')
        || str_contains($title, 'strike')
        || str_contains($type, 'strike')
        || str_contains($title, 'забаст')
        || str_contains($title, 'atm')
        || str_contains($title, 'trenord');
}

protected function importantAlertLine(MobilityAlert $alert): string
{
    $title = mb_strtolower($alert->title);

    if (str_contains($title, 'sciopero') || str_contains($title, 'strike') || str_contains($title, 'забаст')) {
        return "• Забастовка ATM / транспорта. Лучше заранее проверить маршрут.\n";
    }

    if (str_contains($title, 'trenord')) {
        return "• Возможны изменения поездов Trenord. Проверьте расписание заранее.\n";
    }

    return "• " . e($alert->title) . "\n";
}

protected function shouldIncludeAlert(MobilityAlert $alert): bool
{
    $title = mb_strtolower($alert->title);
    $url = mb_strtolower($alert->url ?? '');
    $type = mb_strtolower($alert->type ?? '');

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
            return false;
        }
    }

    $important = [
        'sciopero',
        'strike',
        'trenord',
        'atm',
        'metro',
        'metropolitana',
        'm1',
        'm2',
        'm3',
        'm4',
        'm5',
        'san siro',
        'partite',
        'partita',
        'manifestazione',
        'manifestazioni',
        'maratona',
        'marathon',
        'chiusura',
        'chiude',
        'lavori',
        'cantieri',
        'circolazione',
        'viabilità',
        'traffico',
        'deviazioni',
    ];

    foreach ($important as $keyword) {
        if (str_contains($title, $keyword) || str_contains($type, $keyword)) {
            return true;
        }
    }

    return false;
}

protected function alertLine(MobilityAlert $alert): string
{
    $place = $alert->district ?: $this->detectReadablePlace($alert->title);
    $text = $this->humanAlertText($alert);

    if ($place) {
        return "• <b>" . e($place) . "</b>\n{$text}\n\n";
    }

    return "• {$text}\n\n";
}

protected function detectReadablePlace(string $title): ?string
{
    $text = mb_strtolower($title);

    return match (true) {
        str_contains($text, 'san siro') => 'San Siro',
        str_contains($text, 'trenord') => 'Trenord',
        str_contains($text, 'm1') => 'M1',
        str_contains($text, 'm2') => 'M2',
        str_contains($text, 'm3') => 'M3',
        str_contains($text, 'm4') => 'M4',
        str_contains($text, 'm5') => 'M5',
        str_contains($text, 'duomo') => 'Duomo',
        str_contains($text, 'centrale') => 'Centrale',
        str_contains($text, 'garibaldi') => 'Garibaldi',
        str_contains($text, 'cadorna') => 'Cadorna',
        str_contains($text, 'citylife') => 'CityLife',
        str_contains($text, 'navigli') => 'Navigli',
        str_contains($text, 'lambrate') => 'Lambrate',
        str_contains($text, 'loreto') => 'Loreto',
        default => null,
    };
}

protected function humanAlertText(MobilityAlert $alert): string
{
    $title = mb_strtolower($alert->title);

    if (str_contains($title, 'sciopero')) {
        return 'Возможна забастовка. Лучше заранее проверить маршрут.';
    }

    if (str_contains($title, 'trenord')) {
        return 'Возможны изменения поездов. Проверьте расписание заранее.';
    }

    if (str_contains($title, 'san siro') || str_contains($title, 'partite') || str_contains($title, 'partita')) {
        return 'Матч или мероприятие. Возможны пробки и нагрузка на метро.';
    }

    if (
        str_contains($title, 'm1') ||
        str_contains($title, 'm2') ||
        str_contains($title, 'm3') ||
        str_contains($title, 'm4') ||
        str_contains($title, 'm5') ||
        str_contains($title, 'metro') ||
        str_contains($title, 'metropolitana')
    ) {
        return 'Изменения в метро. Лучше проверить маршрут перед выездом.';
    }

    if (
        str_contains($title, 'chiusura') ||
        str_contains($title, 'chiude') ||
        str_contains($title, 'lavori') ||
        str_contains($title, 'cantieri')
    ) {
        return 'Работы или перекрытия. Лучше заложить больше времени.';
    }

    if (str_contains($title, 'manifestazione') || str_contains($title, 'manifestazioni')) {
        return 'Мероприятие или демонстрация. Возможны задержки на дорогах.';
    }

    if (str_contains($title, 'maratona') || str_contains($title, 'marathon')) {
        return 'Марафон или спортивное событие. Возможны перекрытия и пробки.';
    }

    return e($alert->title);
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
                'thread_id' => $threadId
                    ? trim($threadId)
                    : null,
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
            }

            usleep(500000);
        }
    }
}