<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDigestBuildJournal;
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

        $configured = $districts->diagnostics();

        if (filled($this->option('district'))) {
            $selectedDistrict = mb_strtolower(trim((string) $this->option('district')));

            $configured = $configured->filter(
                fn (array $route): bool => $route['key'] === $selectedDistrict
            );
        }

        $invalidRoutes = $configured->reject(
            fn (array $route): bool => $route['valid']
        );

        foreach ($invalidRoutes as $route) {
            $this->error('District '.$route['key'].' skipped: '.implode(', ', $route['errors']));
        }

        $routes = $districts->routes();
        if (filled($this->option('district')) && $routes->isNotEmpty()) {
            $route = $districts->find((string) $this->option('district'));
            if ($route === null) {
                $this->error('District route is not configured or is incomplete.');

                return self::FAILURE;
            }

            $routes = collect([$route]);
        }

        $journal = app(TelegramDigestBuildJournal::class);
        $journalContexts = [];
        if ($routes->isNotEmpty()) {
            foreach ($routes as $route) {
                $journalContexts[$route['key']] = [
                    'operation_key' => 'morning_digest:'.$date->toDateString().':'.$route['key'],
                    'notification_type' => 'morning_digest',
                    'bot' => 'analytics',
                    'chat_id' => (string) $route['chat_id'],
                    'message_thread_id' => (string) $route['duty_thread_id'],
                    'district' => $route['key'],
                    'scheduled_for' => $date->copy()->setTime(8, 0),
                ];
            }
        } elseif ($configured->isEmpty() && ! filled($this->option('district'))) {
            foreach ($this->telegramTargets() as $index => $target) {
                $journalContexts[$index] = [
                    'operation_key' => 'morning_digest:'.$date->toDateString().':milan:'.($index + 1),
                    'notification_type' => 'morning_digest',
                    'bot' => 'work',
                    'chat_id' => (string) $target['chat_id'],
                    'message_thread_id' => filled($target['thread_id'] ?? null) ? (string) $target['thread_id'] : null,
                    'scheduled_for' => $date->copy()->setTime(8, 0),
                ];
            }
        }

        $journalContexts = $this->option('dry-run') ? [] : $journalContexts;
        foreach ($journalContexts as $context) {
            $journal->reserve($context);
        }

        try {
            $sourceAlerts = MobilityAlert::query()
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
                ->get();
        } catch (Throwable $exception) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'morning_alert_source', 'source_error');
            }

            throw $exception;
        }

        $alerts = $sourceAlerts
            ->filter(fn (MobilityAlert $alert) => $this->shouldShowInWorkerDigest($alert))
            ->values();

        foreach ($journalContexts as $context) {
            $journal->record(
                $context,
                'morning_alert_source',
                $sourceAlerts->isEmpty() ? 'no_input' : 'loaded',
                ['input_count' => $sourceAlerts->count()],
            );
            $includedCount = $alerts->count();
            $journal->record($context, 'morning_filter', $includedCount > 0 ? 'complete' : 'no_significant_events', [
                'input_count' => $sourceAlerts->count(),
                'filtered_count' => $sourceAlerts->count() - $includedCount,
                'included_count' => $includedCount,
            ]);
        }

        if ($routes->isNotEmpty()) {
            $failed = $invalidRoutes->isNotEmpty();

            foreach ($routes as $route) {
                $context = $journalContexts[$route['key']] ?? null;
                try {
                    $routeAlerts = $this->alertsForDistrict($alerts, $route, $routes);
                    if ($context !== null) {
                        $journal->record($context, 'morning_filter', $routeAlerts->count() > 0 ? 'complete' : 'no_significant_events', [
                            'input_count' => $alerts->count(),
                            'filtered_count' => $alerts->count() - $routeAlerts->count(),
                            'included_count' => $routeAlerts->count(),
                        ]);
                    }
                    $message = $this->buildMessage(
                        $date,
                        $routeAlerts,
                        $route,
                        $context === null ? [] : [$context],
                    );

                    if ($context !== null) {
                        $messagePresent = trim($message) !== '';
                        $journal->record($context, 'morning_format', $messagePresent ? 'built' : 'no_message', [
                            'message_present' => $messagePresent,
                        ]);
                    }

                    if ($this->option('dry-run')) {
                        $this->renderDryRun($route['label'], $message);

                        continue;
                    }

                    $messageId = app(TelegramOutboundMonitor::class)->withContext($context ?? [
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

            $failed
                ? $this->error('One or more district morning digests failed.')
                : $this->info('District morning digests sent.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if ($configured->isNotEmpty()) {
            return self::FAILURE;
        }

        if (filled($this->option('district'))) {
            $this->error('District routes are not configured.');

            return self::FAILURE;
        }

        $contexts = array_values($journalContexts);
        $message = $this->buildMessage($date, $alerts, null, $contexts);

        foreach ($contexts as $context) {
            $messagePresent = trim($message) !== '';
            $journal->record($context, 'morning_format', $messagePresent ? 'built' : 'no_message', [
                'message_present' => $messagePresent,
            ]);
        }

        if ($this->option('dry-run')) {
            $this->renderDryRun('Milan', $message);

            return self::SUCCESS;
        }

        $this->sendTelegram($message, $bot, $contexts);

        $this->info('Daily shift digest sent.');

        return self::SUCCESS;
    }

    protected function buildMessage(Carbon $date, $alerts, ?array $route = null, array $journalContexts = []): string
    {
        $journal = app(TelegramDigestBuildJournal::class);
        try {
            $weather = app(MilanWeatherService::class)->morningForecast(
                CarbonImmutable::instance($date),
                (float) ($route['latitude'] ?? 45.4642),
                (float) ($route['longitude'] ?? 9.1900),
                config('app.timezone', 'Europe/Rome'),
            );
        } catch (Throwable $exception) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'morning_weather_source', 'source_error');
            }

            throw $exception;
        }

        $weatherReason = $weather['unavailable_reason'] ?? null;
        $forecastPoints = collect($weather['points'] ?? [])->filter(fn (?array $point): bool => $point !== null)->count();
        $weatherOutcome = $weatherReason !== null && $weatherReason !== 'date_out_of_range'
            ? 'source_error'
            : ($forecastPoints > 0 ? 'loaded' : 'no_input');
        foreach ($journalContexts as $context) {
            $journal->record($context, 'morning_weather_source', $weatherOutcome, ['source_messages' => $forecastPoints]);
        }

        $alertInputCount = collect($alerts)->count();
        try {
            $alerts = $this->deduplicateAlerts($alerts)
                ->filter(fn (array $alert): bool => $this->isImportantWorkerAlert($alert))
                ->values();
        } catch (Throwable $exception) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'morning_filter', 'calculation_error', ['input_count' => $alertInputCount]);
            }

            throw $exception;
        }

        foreach ($journalContexts as $context) {
            $includedCount = $alerts->count();
            $journal->record($context, 'morning_filter', $includedCount > 0 ? 'complete' : 'no_significant_events', [
                'input_count' => $alertInputCount,
                'filtered_count' => max(0, $alertInputCount - $includedCount),
                'included_count' => $includedCount,
            ]);
        }

        try {
            $label = trim((string) ($route['label'] ?? ''));

            $text = Arr::random($this->greetings)
                .($label !== '' ? ' · <b>'.e($label).'</b>' : '')
                ."\n\n";

            $weatherDate = $date->copy()->setTimezone(
                config('app.timezone', 'Europe/Rome')
            );

            $weatherHeading = $weatherDate->isToday()
                ? 'Погода на сегодня'
                : 'Погода на '.$weatherDate->format('d.m.Y');

            $text .= '🌤 <b>'.e($weatherHeading)."</b>\n";

            $points = $weather['points'] ?? [];

            $hasForecast = collect($points)->contains(
                fn (?array $point): bool => $point !== null
            );

            if (! $hasForecast) {
                if (($weather['unavailable_reason'] ?? null) === 'date_out_of_range') {
                    $text .= "Прогноз недоступен для 08:00, 13:00 и 15:00: бесплатный план охватывает сегодня и следующие два дня.\n";
                } else {
                    $text .= "Прогноз временно недоступен для 08:00, 13:00 и 15:00.\n";
                }
            } else {
                foreach (['08:00', '13:00', '15:00'] as $time) {
                    $text .= $this->formatWeatherPoint(
                        $time,
                        $points[$time] ?? null
                    );
                }
            }

            $text = rtrim($text);

            // Сначала предупреждения об опасной погоде.
            if (! empty($weather['advice'])) {
                $text .= "\n\n".e((string) $weather['advice']);
            } elseif ($hasForecast) {
                // Если серьёзных рисков нет, предлагаем зонт при вероятности дождя >= 40%.
                $umbrellaAdvice = $this->friendlyWeatherAdvice(
    $points,
    $date->toDateString().'|'.($route['key'] ?? 'milan')
);

                if ($umbrellaAdvice !== null) {
                    $text .= "\n\n".e($umbrellaAdvice);
                }
            }

            // Для тестового dry-run скрываем юридический блок.
            // В настоящей рассылке атрибуция и предупреждение сохраняются.
            if ($hasForecast && ! $this->option('dry-run')) {
                $text .= "\n\nИсточник: <code>WeatherAPI.com</code> · перевод и округление.";
                $text .= "\n".'ℹ️ Погодные данные приведены только для общего сведения. Прогноз вероятностный и может быть неточным для конкретного места и времени. Не используйте его как единственный источник решений о личной безопасности, авиации, морской навигации, экстренном планировании и иных вопросах, критичных для безопасности. Для таких решений сверяйтесь с официальными метеослужбами и предупреждениями компетентных органов.';
            }

            if ($alerts->isNotEmpty()) {
                $text .= "\n\n⚠️ <b>Сегодня важно</b>\n";

                foreach ($alerts->take(3) as $alert) {
                    $text .= '• '.$this->workerAlertLine($alert)."\n";
                }
            }

            $text .= "\n\n".Arr::random($this->endings);

            return trim($text);
        } catch (Throwable $exception) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'morning_format', 'calculation_error');
            }

            throw $exception;
        }
    }

    protected function formatWeatherPoint(string $time, ?array $point): string
    {
        if ($point === null) {
            return '🌤 <b>'.e($time).' · Прогноз временно недоступен</b>'."\n\n";
        }

        $condition = is_string($point['condition'] ?? null)
            ? trim($point['condition'])
            : '';

        $emoji = is_string($point['emoji'] ?? null) && filled($point['emoji'])
            ? $point['emoji']
            : '🌤';

        $heading = e($time);

        if ($condition !== '') {
            $heading .= ' · '.e($condition);
        }

        $temperature = $point['temperature_c'] ?? null;

        if ($temperature !== null) {
            $heading .= ' — '.e($this->formatTemperature((float) $temperature));
        }

        $line = e($emoji).' <b>'.$heading."</b>\n";

        // Только ощущаемая температура.
        // Влажность, ветер и осадки из сообщения убраны.
        $apparent = $point['apparent_temperature_c'] ?? null;

        if ($apparent !== null) {
            $line .= 'Ощущается '
                .sprintf('%+d°', (int) round((float) $apparent))
                ."\n";
        }

        return $line."\n";
    }


protected function friendlyWeatherAdvice(array $points, string $seed): ?string
{
    $rainTime = null;
    $fog = false;
    $wind = false;
    $cold = false;
    $veryCold = false;
    $hot = false;
    $sunnyCount = 0;

    foreach (['08:00', '13:00', '15:00'] as $time) {
        $point = $points[$time] ?? null;

        if (! is_array($point)) {
            continue;
        }

        $code = $point['weather_code'] ?? null;
        $chance = $point['precipitation_probability_pct'] ?? null;
        $rain = $point['rain_mm'] ?? null;
        $windSpeed = $point['wind_speed_kmh'] ?? null;
        $feels = $point['apparent_temperature_c'] ?? null;

        $rainCodes = [
            1063, 1150, 1153, 1180, 1183,
            1186, 1189, 1192, 1195, 1240,
            1243, 1246, 1273, 1276,
        ];

        if (
            $rainTime === null
            && (
                in_array($code, $rainCodes, true)
                || (is_numeric($chance) && $chance >= 40)
                || (is_numeric($rain) && $rain >= 0.3)
            )
        ) {
            $rainTime = $time;
        }

        if (in_array($code, [1030, 1135, 1147], true)) {
            $fog = true;
        }

        if (is_numeric($windSpeed) && $windSpeed >= 30) {
            $wind = true;
        }

        if (is_numeric($feels)) {
            if ($feels <= 2) {
                $veryCold = true;
            } elseif ($feels <= 9) {
                $cold = true;
            }

            if ($feels >= 28) {
                $hot = true;
            }
        }

        if (in_array($code, [1000, 1003], true)) {
            $sunnyCount++;
        }
    }

    if ($fog) {
        $type = 'fog';
        $variants = [
            '🌫 Сегодня туманно — будьте внимательнее по дороге.',
            '🌫 Туман за окном! Не торопитесь на переходах.',
            '👀 Видимость может быть хуже обычного — берегите себя в пути.',
        ];
    } elseif ($rainTime !== null && $wind) {
        $type = 'rain-wind';
        $variants = [
            '🌂 Сегодня дождливо и ветрено — куртка с капюшоном пригодится!',
            '☂️ Дождь и ветер в прогнозе. Одевайтесь так, чтобы было комфортно в дороге.',
            '🌧 Погода немного капризничает — захватите непромокаемую куртку.',
        ];
    } elseif ($rainTime !== null) {
        $type = 'rain';

        $period = $rainTime === '08:00' ? 'Утром' : 'Днём';

        $variants = [
            '☂️ Кажется, сегодня пригодится зонтик. Не забудьте его дома!',
            '🌧 '.$period.' возможен дождик — захватите зонт.',
            '☔ Небо сегодня может покапризничать. Зонтик будет кстати!',
            '🌦 Возможен дождь — пусть он не застанет вас врасплох.',
        ];
    } elseif ($veryCold) {
        $type = 'very-cold';
        $variants = [
            '🧤 На улице холодно — не забудьте перчатки!',
            '🧣 Сегодня лучше одеться потеплее. Берегите себя!',
            '❄️ Холодновато! Тёплая куртка сегодня точно пригодится.',
        ];
    } elseif ($hot) {
        $type = 'hot';
        $variants = [
            '💧 Сегодня жарковато — не забывайте пить воду между уборками!',
            '☀️ Будет тепло! Держите бутылочку воды под рукой.',
            '🥤 В жару особенно важно пить воду. Не забывайте про себя!',
        ];
    } elseif ($wind) {
        $type = 'wind';
        $variants = [
            '🍃 Сегодня ветрено — придерживайте двери при входе и выходе.',
            '💨 Ветер разгулялся! Будьте внимательнее на улице.',
            '🧥 Сегодня ветрено — лёгкая ветровка может пригодиться.',
        ];
    } elseif ($cold) {
        $type = 'cold';
        $variants = [
            '🧥 Утром свежо! Прихватите что-нибудь тёплое.',
            '☕ Сегодня прохладно — одевайтесь уютнее!',
            '🧣 На улице свежо. Дополнительный слой одежды не помешает.',
        ];
    } elseif ($sunnyCount >= 2) {
        $type = 'sunny';
        $variants = [
            '😎 Кажется, сегодня солнце на нашей стороне! Очки пригодятся.',
            '☀️ Похоже, день будет солнечным — отличной вам смены!',
            '🌤 Погода обещает быть приятной. Пусть и работа идёт легко!',
        ];
    } else {
        return null;
    }

    return $this->pickWeatherAdvice($variants, $seed.'|'.$type);
}

protected function pickWeatherAdvice(array $variants, string $seed): string
{
    // Одна и та же фраза для района в течение дня.
    // На следующий день вариант может измениться.
    $hash = hash('sha256', $seed);
    $index = hexdec(substr($hash, 0, 8)) % count($variants);

    return $variants[$index];
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
            ->reject(fn (array $alert): bool => str_contains(
                mb_strtolower(($alert['title'] ?? '').' '.($alert['description'] ?? '')),
                'regolare'
            ))
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

        if (str_contains($type, 'strike')
            || preg_match('/sciopero|забаст|\bstrike\b/iu', $text)) {
            return false;
        }

        if (($alert['risk'] ?? null) !== 'critical'
            || preg_match('/\bM[1-5]\b/iu', implode(' ', [
                (string) ($alert['district'] ?? ''),
                (string) ($alert['title'] ?? ''),
            ]))) {
            return false;
        }

        return preg_match(
            '/interruzione totale|sospensione totale|blocco (?:totale|della circolazione)|servizio (?:completamente )?sospeso|полная остановка движения/iu',
            $text
        ) === 1;
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

        return trim(mb_strimwidth($value, 0, 240, '…'))
            ?: 'Подробности уточняются в официальном источнике.';
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

    protected function sendTelegram(string $message, TelegramBotService $bot, array $journalContexts = []): void
    {
        foreach ($this->telegramTargets() as $index => $target) {
            $send = fn () => $bot->sendMessage(
                (string) $target['chat_id'],
                $message,
                filled($target['thread_id'] ?? null) ? (string) $target['thread_id'] : null,
            );
            $context = $journalContexts[$index] ?? null;

            if ($context !== null) {
                app(TelegramOutboundMonitor::class)->withContext($context, $send);
            } else {
                $send();
            }
        }
    }
}
