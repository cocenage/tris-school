<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Weather\MilanWeatherService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

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

        if ($this->option('date') && ! $this->option('dry-run')
            && ! app(MilanWeatherService::class)->supportsForecastDate(
                CarbonImmutable::instance($date),
                config('app.timezone', 'Europe/Rome'),
            )) {
            $this->error('WeatherAPI Free forecasts are available only for today and the next two days; no digest was sent.');

            return self::FAILURE;
        }

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

        $configured = $districts->diagnostics();
        if (filled($this->option('district'))) {
            $selectedDistrict = mb_strtolower(trim((string) $this->option('district')));
            $configured = $configured->filter(fn (array $route): bool => $route['key'] === $selectedDistrict);
        }

        $invalidRoutes = $configured->reject(fn (array $route): bool => $route['valid']);
        foreach ($invalidRoutes as $route) {
            $this->error('District '.$route['key'].' skipped: '.implode(', ', $route['errors']));
        }

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

            $failed = $invalidRoutes->isNotEmpty();

            foreach ($routes as $route) {
                try {
                    $message = $this->buildMessage(
                        $date,
                        $this->alertsForDistrict($alerts, $route, $routes),
                        $route,
                    );

                    if ($this->option('dry-run')) {
                        $this->renderDryRun($route['label'], $message);

                        continue;
                    }

                    $messageId = app(TelegramOutboundMonitor::class)->withContext([
                        'operation_key' => 'morning_digest:'.$date->toDateString().':'.$route['key'],
                        'notification_type' => 'morning_digest',
                        'district' => $route['key'],
                        'scheduled_for' => $date->copy()->setTime(8, 0),
                    ], fn () => $bot->sendAnalyticsMessage(
                        (string) $route['chat_id'],
                        $message,
                        (string) $route['duty_thread_id'],
                    ));
                    if ($messageId === null) {
                        $failed = true;
                        $this->error('District '.$route['key'].' morning digest was not sent.');
                    }
                } catch (Throwable) {
                    $failed = true;
                    $this->error('District '.$route['key'].' morning digest failed.');
                }
            }

            if ($this->option('dry-run')) {
                return $failed ? self::FAILURE : self::SUCCESS;
            }

            $failed ? $this->error('One or more district morning digests failed.') : $this->info('District morning digests sent.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if ($configured->isNotEmpty()) {
            return self::FAILURE;
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
        $weather = app(MilanWeatherService::class)->morningForecast(
            CarbonImmutable::instance($date),
            (float) ($route['latitude'] ?? 45.4642),
            (float) ($route['longitude'] ?? 9.1900),
            config('app.timezone', 'Europe/Rome'),
        );

        $alerts = $this->deduplicateAlerts($alerts)
            ->filter(fn (array $alert): bool => $this->isImportantWorkerAlert($alert))
            ->values();
        $label = trim((string) ($route['label'] ?? ''));
        $text = Arr::random($this->greetings).($label !== '' ? ' · <b>'.e($label).'</b>' : '')."\n\n";
        $weatherDate = $date->copy()->setTimezone(config('app.timezone', 'Europe/Rome'));
        $weatherHeading = $weatherDate->isToday() ? 'Погода на сегодня' : 'Погода на '.$weatherDate->format('d.m.Y');
        $text .= '🌤 <b>'.e($weatherHeading)."</b>\n";
        $points = $weather['points'] ?? [];
        $hasForecast = collect($points)->contains(fn (?array $point): bool => $point !== null);

        if (! $hasForecast) {
            if (($weather['unavailable_reason'] ?? null) === 'date_out_of_range') {
                $text .= "Прогноз недоступен: бесплатный сервис охватывает сегодня и следующие два дня.\n";
            } else {
                $text .= "Прогноз временно недоступен.\n";
            }
        } else {
            foreach (['08:00', '13:00', '15:00'] as $time) {
                $text .= $this->formatWeatherPoint($time, $points[$time] ?? null);
            }
        }

        if (! empty($weather['advice'])) {
            $text .= e((string) $weather['advice'])."\n";
        }

        if ($hasForecast) {
            $text .= '<a href="https://www.weatherapi.com/">WeatherAPI.com</a> · перевод и округление.'."\n";
            $text .= 'ℹ️ Прогноз вероятностный и может быть неточным для места и времени. Не используйте его как единственную основу решений о безопасности; сверяйтесь с официальными метеослужбами.'."\n";
        }

        if ($alerts->isNotEmpty()) {
            $text .= "\n⚠️ <b>Сегодня важно</b>\n";
            foreach ($alerts->take(3) as $alert) {
                $text .= '• '.$this->workerAlertLine($alert)."\n";
            }
        }

        $text .= "\n".Arr::random($this->endings);

        return trim($text);
    }

    protected function formatWeatherPoint(string $time, ?array $point): string
    {
        if ($point === null) {
            return '<b>'.e($time).' — Прогноз временно недоступен</b>'."\n";
        }

        $condition = trim(implode(' ', array_filter([
            $point['emoji'] ?? null,
            $point['condition'] ?? null,
        ], fn (?string $value): bool => filled($value))));
        $line = '<b>'.e($time).($condition !== '' ? ' — '.e($condition) : '')."</b>\n";

        $temperature = $point['temperature_c'] ?? null;
        $apparent = $point['apparent_temperature_c'] ?? null;
        if ($temperature !== null || $apparent !== null) {
            $temperatureText = $temperature !== null ? $this->formatTemperature((float) $temperature) : null;
            $apparentText = $apparent !== null ? $this->formatTemperature((float) $apparent) : null;
            $line .= 'Температура: '.($temperatureText ?? '—')
                .($apparentText !== null ? ' (ощущается '.$apparentText.')' : '')."\n";
        }

        if (($point['relative_humidity_pct'] ?? null) !== null) {
            $line .= '💧 Влажность: '.(int) $point['relative_humidity_pct'].'%'."\n";
        }

        $windSpeed = $point['wind_speed_kmh'] ?? null;
        $windDirection = $point['wind_direction'] ?? null;
        if ($windSpeed !== null || $windDirection !== null) {
            $line .= '💨 Ветер: '.($windSpeed !== null ? (int) round((float) $windSpeed).' км/ч' : '—')
                .($windDirection !== null ? ', '.e((string) $windDirection) : '')."\n";
        }

        if (($point['precipitation_probability_pct'] ?? null) !== null) {
            $line .= '🌧 Осадки: '.(int) $point['precipitation_probability_pct'].'%';
            if (($point['rain_mm'] ?? null) !== null) {
                $line .= ' ('.number_format((float) $point['rain_mm'], 1, ',', '').' мм)';
            }
            $line .= "\n";
        } elseif (($point['rain_mm'] ?? null) !== null) {
            $line .= '🌧 Осадки: '.number_format((float) $point['rain_mm'], 1, ',', '')." мм\n";
        }

        return $line;
    }

    protected function formatTemperature(float $temperature): string
    {
        return sprintf('%+d°C', (int) round($temperature));
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
        $title = trim((string) ($alert['title'] ?? ''));
        $description = trim((string) ($alert['description'] ?? ''));
        $sourceText = $title.' '.$description;

        if ((str_contains(mb_strtolower((string) ($alert['type'] ?? '')), 'strike')
                || preg_match('/sciopero|забаст|\bstrike\b/iu', $sourceText))
            && preg_match('/dalle\s*(\d{1,2}:\d{2})\s*a\s*fine\s*servizio/iu', $description, $matches)) {
            $scope = preg_match('/trasporto|transport|общественн/iu', $sourceText)
                ? 'общественного транспорта'
                : 'транспорта';

            return 'Забастовка '.$scope.' с '.$matches[1].' до конца движения.';
        }

        $summary = $description !== '' && mb_strlen($description) > mb_strlen($title)
            ? $description
            : $title;
        $summary = preg_replace('/https?:\/\/\S+/iu', '', strip_tags($summary)) ?: '';
        $summary = preg_replace('/\s+/u', ' ', $summary) ?: '';

        return e(trim(mb_strimwidth($summary, 0, 160, '…')));
    }

    protected function isImportantWorkerAlert(array $alert): bool
    {
        $text = mb_strtolower(implode(' ', [
            (string) ($alert['title'] ?? ''),
            (string) ($alert['description'] ?? ''),
        ]));
        $type = mb_strtolower((string) ($alert['type'] ?? ''));

        if (str_contains($type, 'strike') || preg_match('/sciopero|забаст|\bstrike\b/iu', $text)) {
            return false;
        }

        if (($alert['risk'] ?? null) !== 'critical'
            || preg_match('/\bM[1-5]\b/iu', implode(' ', [
                (string) ($alert['district'] ?? ''),
                (string) ($alert['title'] ?? ''),
            ]))) {
            return false;
        }

        return preg_match('/interruzione totale|sospensione totale|blocco (?:totale|della circolazione)|servizio (?:completamente )?sospeso|полная остановка движения/iu', $text) === 1;
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
