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

    protected $description = 'Send daily mobility digest to Telegram';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->addDay()->startOfDay();

        $alerts = MobilityAlert::query()
            ->whereDate('starts_at', $date)
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
            return "🚦 <b>Milano Mobility — {$dateText}</b>\n\n✅ На завтра критичных событий не найдено.";
        }

        $high = $alerts->where('risk', 'high');
        $medium = $alerts->where('risk', 'medium');
        $low = $alerts->where('risk', 'low');

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

        if ($low->isNotEmpty()) {
            $text .= "🟢 <b>Низкий риск</b>\n";
            foreach ($low as $alert) {
                $text .= $this->alertLine($alert);
            }
            $text .= "\n";
        }

        $text .= "💡 <b>Рекомендация:</b>\n";
        $text .= "проверить маршруты заранее, особенно если есть выезды в центр, CityLife, San Siro, Centrale или Garibaldi.";

        return $text;
    }

    protected function alertLine(MobilityAlert $alert): string
    {
        $source = strtoupper($alert->source);
        $district = $alert->district ? " / {$alert->district}" : '';
        $title = e($alert->title);

        return "— <b>{$source}{$district}</b>: {$title}\n";
    }

protected function sendTelegram(string $message): void
{
    $token = config('services.telegram.bot_token');
    $chatId = config('services.telegram.mobility_chat_id');

    if (! $token || ! $chatId) {
        Log::warning('Mobility digest skipped: missing Telegram config', [
            'token_exists' => filled($token),
            'chat_id' => $chatId,
        ]);

        return;
    }

    try {
        $response = Http::timeout(20)
            ->retry(2, 1000)
            ->withoutVerifying()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
    } catch (\Throwable $e) {
        Log::warning('Mobility digest telegram connection failed', [
            'error' => $e->getMessage(),
        ]);

        return;
    }

    if (! $response->successful()) {
        Log::warning('Mobility digest telegram failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
    }
}
}