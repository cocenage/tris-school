<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilityDigestCommand extends Command
{
    protected $signature = 'mobility:digest {--date=}';

    protected $description = 'Send daily mobility digest to Telegram forums';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->addDay()->startOfDay();

        $alerts = MobilityAlert::query()
            ->whereDate('starts_at', $date)
            ->whereIn('risk', ['high', 'medium'])
            ->orderByRaw("CASE risk WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('source')
            ->get();

        $message = $this->buildMessage($date, $alerts);

        $this->sendTelegram($message);

        MobilityAlert::query()
            ->whereDate('starts_at', $date)
            ->whereNull('sent_at')
            ->update(['sent_at' => now()]);

        $this->info('Mobility digest sent.');

        return self::SUCCESS;
    }

    protected function buildMessage(Carbon $date, $alerts): string
    {
        $dateText = $date->format('d.m.Y');

        if ($alerts->isEmpty()) {
            return "🚦 <b>Milano Mobility — {$dateText}</b>\n\n✅ На завтра критичных транспортных событий не найдено.";
        }

        $high = $alerts->where('risk', 'high');
        $medium = $alerts->where('risk', 'medium');

        $text = "🚦 <b>Milano Mobility — {$dateText}</b>\n\n";

        if ($high->isNotEmpty()) {
            $text .= "🔴 <b>Высокий риск</b>\n";
            foreach ($high as $alert) {
                $text .= $this->alertLine($alert);
            }
            $text .= "\n";
        }

        if ($medium->isNotEmpty()) {
            $text .= "🟡 <b>Средний риск</b>\n";
            foreach ($medium as $alert) {
                $text .= $this->alertLine($alert);
            }
            $text .= "\n";
        }

        $text .= "💡 <b>Рекомендация:</b>\n";
        $text .= "проверить маршруты заранее, особенно если есть выезды в центр, CityLife, San Siro, Centrale, Garibaldi или по линии M2.";

        return $text;
    }

    protected function alertLine(MobilityAlert $alert): string
    {
        $source = strtoupper($alert->source);
        $type = $this->russianType($alert);
        $district = $alert->district ? " / {$alert->district}" : '';
        $title = e($this->russifyTitle($alert->title));
        $url = $alert->url ? ' <a href="' . e($alert->url) . '">источник</a>' : '';

        return "— <b>{$source}{$district}</b>: {$type}. {$title}{$url}\n";
    }

    protected function russianType(MobilityAlert $alert): string
    {
        return match ($alert->type) {
            'strike' => 'забастовка',
            'roadwork' => 'работы / перекрытия',
            'event' => 'мероприятие',
            default => 'изменения транспорта',
        };
    }

    protected function russifyTitle(string $title): string
    {
        $replacements = [
            'Sciopero' => 'Забастовка',
            'sciopero' => 'забастовка',

            'Cambiamenti programmati al servizio' => 'Плановые изменения транспорта',
            'cambiamenti programmati al servizio' => 'плановые изменения транспорта',
            'cambiamenti al servizio' => 'изменения транспорта',
            'cambiano' => 'меняются',

            'Rinnovo' => 'Обновление',
            'rinnovo' => 'обновление',

            'lavori' => 'работы',
            'cantieri' => 'ремонтные работы',

            'chiude' => 'закрывается',
            'chiusura' => 'закрытие',

            'stazione' => 'станция',
            'servizio' => 'сервис',
            'circolazione' => 'движение',
            'viabilità' => 'дорожное движение',

            'dall’' => 'с ',
            'dal ' => 'с ',
            'al ' => 'до ',
            'durante' => 'во время',
            'sera' => 'вечером',
            'ultime partenze' => 'последние отправления',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $title);
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
                [$chatId, $threadId] = array_pad(explode(':', $item, 2), 2, null);

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
        $token = config('services.telegram.bot_token');
        $targets = $this->telegramTargets();

        if (! $token || empty($targets)) {
            Log::warning('Mobility digest skipped: missing Telegram config', [
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
                $response = Http::timeout(20)
                    ->withoutVerifying()
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            } catch (\Throwable $e) {
                Log::warning('Mobility digest telegram connection failed', [
                    'chat_id' => $target['chat_id'],
                    'thread_id' => $target['thread_id'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Mobility digest telegram failed', [
                    'chat_id' => $target['chat_id'],
                    'thread_id' => $target['thread_id'],
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        }
    }
}