<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Weather\MilanWeatherService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class MobilityDigestCommand extends Command
{
    protected $signature = 'mobility:digest {--date=} {--district=} {--dry-run}';

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

    public function handle(
        TelegramDistrictRouteRegistry $districts,
        TelegramBotService $bot,
    ): int {
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

        $routes = $districts->routes();

        if ($routes->isNotEmpty()) {
            if (filled($this->option('district'))) {
                $route = $districts->find((string) $this->option('district'));

                if ($route === null) {
                    $this->error('District route is not configured or is incomplete.');

                    return self::FAILURE;
                }

                $routes = collect([$route]);
            }

            $failed = false;

            foreach ($routes as $route) {
                $message = $this->buildMessage(
                    $date,
                    $this->alertsForDistrict($alerts, $route, $routes),
                    $route,
                );

                if ($this->option('dry-run')) {
                    $this->renderDryRun($route['label'], $message);

                    continue;
                }

                $messageId = $bot->sendMessage(
                    (string) $route['chat_id'],
                    $message,
                    (string) $route['duty_thread_id'],
                );
                $failed = $failed || $messageId === null;
            }

            if ($this->option('dry-run')) {
                return self::SUCCESS;
            }

            $failed ? $this->error('One or more district morning digests failed.') : $this->info('District morning digests sent.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if (filled($this->option('district'))) {
            $this->error('District routes are not configured.');

            return self::FAILURE;
        }

        $message = $this->buildMessage($date, $alerts);

        if ($this->option('dry-run')) {
            $this->renderDryRun('Milan', $message);

            return self::SUCCESS;
        }

        $this->sendTelegram($message, $bot);

        $this->info('Daily shift digest sent.');

        return self::SUCCESS;
    }

    protected function buildMessage(Carbon $date, $alerts, ?array $route = null): string
    {
        $weather = app(MilanWeatherService::class)->today(
            (float) ($route['latitude'] ?? 45.4642),
            (float) ($route['longitude'] ?? 9.1900),
            config('app.timezone', 'Europe/Rome'),
        );

        $alerts = $this->deduplicateAlerts($alerts);
        $label = trim((string) ($route['label'] ?? ''));
        $text = Arr::random($this->greetings).($label !== '' ? ' · <b>'.e($label).'</b>' : '')."\n\n";
        $text .= "🌤 <b>Погода</b>\n";
        $text .= e((string) ($weather['emoji'] ?? '🌤')).' '.e((string) ($weather['summary'] ?? 'погода временно недоступна'))."\n";

        if (! empty($weather['advice'])) {
            $text .= e((string) $weather['advice'])."\n";
        }

        $text .= "\n🚦 <b>Передвижение</b>\n";

        if ($alerts->isEmpty()) {
            $text .= "\nСущественных ограничений на транспорте не обнаружено.\n";
        } else {
            foreach ($alerts->take(6) as $alert) {
                $text .= "\n".$this->workerAlertLine($alert);
            }
        }

        $text .= "\n".Arr::random($this->endings);

        return trim($text);
    }

    protected function alertsForDistrict($alerts, array $route, $routes)
    {
        return collect($alerts)->filter(function (MobilityAlert $alert) use ($route, $routes): bool {
            $value = $this->normalizedDistrict($alert->district);

            if ($value === '') {
                return true;
            }

            $matchedRoute = collect($routes)->first(fn (array $candidate): bool => in_array($value, [
                $this->normalizedDistrict($candidate['key'] ?? null),
                $this->normalizedDistrict($candidate['label'] ?? null),
            ], true));

            return $matchedRoute === null || $matchedRoute['key'] === $route['key'];
        })->values();
    }

    protected function normalizedDistrict(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    protected function renderDryRun(string $label, string $message): void
    {
        $this->newLine();
        $this->line('===== DRY RUN MORNING DIGEST · '.$label.' =====');
        foreach (explode("\n", $message) as $line) {
            $this->line($line);
        }
        $this->line('===================================');
        $this->newLine();
    }

    protected function deduplicateAlerts($alerts)
    {
        $normalizer = app(MobilityAlertSyncService::class);
        $alerts = $normalizer->filterRepresentedRawAlerts(collect($alerts));

        return collect($alerts)
            ->filter(fn (MobilityAlert $alert) => $this->shouldShowInWorkerDigest($alert))
            ->flatMap(function (MobilityAlert $alert) use ($normalizer) {
                $lineEvents = $normalizer->splitTelegramStatusEvents($alert->title);

                if ($lineEvents === []) {
                    return [[
                        'type' => $alert->type,
                        'risk' => $alert->risk,
                        'district' => $alert->district,
                        'title' => $alert->title,
                        'description' => $alert->description,
                        'source' => $alert->source,
                        'starts_at' => optional($alert->starts_at)->toDateString(),
                    ]];
                }

                return collect($lineEvents)->map(fn (array $event): array => [
                    'type' => $event['type'],
                    'risk' => $event['risk'],
                    'district' => $event['line'],
                    'title' => $event['title'],
                    'description' => $event['description'],
                    'source' => $alert->source,
                    'starts_at' => optional($alert->starts_at)->toDateString(),
                ])->all();
            })
            ->reject(fn (array $alert): bool => str_contains(mb_strtolower(($alert['title'] ?? '').' '.($alert['description'] ?? '')), 'regolare'))
            ->unique(fn (array $alert): string => $normalizer->canonicalFingerprint(
                $alert['source'] ?? 'mobility',
                $alert['title'] ?? '',
                $alert['description'] ?? null,
                $alert['type'] ?? null,
                $alert['starts_at'] ?? null,
            ))
            ->values();
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
            || str_contains($title, 'chiusa')
            || str_contains($title, 'sospesa')
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

    protected function workerAlertLine(array $alert): string
    {
        $label = $alert['district'] ?? 'transport';
        $type = $alert['type'] ?? 'info';
        $summary = match ($type) {
            'partial_closure' => 'частично ограничено движение между Gobba и Cologno Nord, работают автобусы BM2.',
            'closure' => 'линия закрыта.',
            default => $this->shortText($alert['description'] ?? $alert['title'] ?? ''),
        };
        $icon = in_array($alert['risk'] ?? null, ['critical', 'high'], true) ? '⚠️' : 'ℹ️';

        return $icon.' <b>'.e($label)."</b>\n".e($summary)."\n";

    }

    protected function normalizedText(?string $value): string
    {
        $value = preg_replace('/https?:\/\/\S+/iu', '', strip_tags((string) $value));
        $value = preg_replace('/[\p{P}\p{S}\s]+/u', ' ', mb_strtolower($value));

        return trim($value);
    }

    protected function shortText(?string $value): string
    {
        $value = $this->normalizedText($value);
        $value = preg_replace('/milanmetrost[a-z.\s]*[èe] anche su chatgpt/iu', '', $value);

        return trim(mb_strimwidth($value, 0, 240, '…')) ?: 'Подробности уточняются в официальном источнике.';
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

    protected function sendTelegram(string $message, TelegramBotService $bot): void
    {
        foreach ($this->telegramTargets() as $target) {
            $bot->sendMessage(
                (string) $target['chat_id'],
                $message,
                filled($target['thread_id'] ?? null) ? (string) $target['thread_id'] : null,
            );
        }
    }
}
