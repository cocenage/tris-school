<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\Weather\MilanWeatherService;
use App\Models\TelegramTopic;

class MobilityDigestCommand extends Command
{
    protected $signature = 'mobility:digest {--date=}';

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

        $this->sendTelegram($message);

        $this->info('Daily shift digest sent.');

        return self::SUCCESS;
    }

protected function buildMessage(Carbon $date, $alerts): string
{
    $weather = app(MilanWeatherService::class)->today();

    $text = "{$weather['emoji']} <b>Доброе утро</b>\n\n";
    $text .= "Сегодня: {$weather['summary']}\n";

    if ($weather['advice']) {
        $text .= "\n{$weather['advice']}\n";
    }

    if ($alerts->isNotEmpty()) {
        $text .= "\n🚦 <b>Передвижение</b>\n\n";

        foreach ($alerts as $alert) {
            $text .= $this->alertLine($alert);
        }
    }

    $text .= "\nХорошей смены.";

    return trim($text);
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
    return TelegramTopic::query()
        ->where('purpose', 'mobility')
        ->where('is_enabled', true)
        ->with('chat')
        ->get()
        ->map(function (TelegramTopic $topic) {
            return [
                'chat_id' => $topic->chat?->telegram_chat_id,
                'thread_id' => $topic->telegram_thread_id,
            ];
        })
        ->filter(fn ($target) => filled($target['chat_id']) && filled($target['thread_id']))
        ->unique(fn ($target) => $target['chat_id'] . ':' . $target['thread_id'])
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