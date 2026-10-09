<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramDistrictRouteRegistry;
use Illuminate\Console\Command;

class MobilityDigestRoutesCommand extends Command
{
    protected $signature = 'mobility:digest-routes';

    protected $description = 'Inspect morning digest district routing without sending messages';

    public function handle(TelegramDistrictRouteRegistry $districts): int
    {
        $configured = $districts->diagnostics()->keyBy('key');
        $hasBotToken = filled(config('services.telegram.bot_token'));
        $rows = [];

        foreach (['navigli', 'lodi', 'como', 'certosa', 'lambrate'] as $key) {
            $route = $configured->get($key);
            $errors = $route['errors'] ?? ['missing_route'];
            if ($route !== null && ! $hasBotToken) {
                $errors[] = 'missing_bot_token';
            }

            $rows[] = [
                ucfirst($key),
                $route === null ? 'missing' : 'configured',
                filled($route['chat_id'] ?? null) ? 'present' : 'missing',
                filled($route['duty_thread_id'] ?? null) ? 'present' : 'missing',
                $errors === [] ? 'yes' : 'no',
                $errors === [] ? '—' : implode(', ', $errors),
            ];
        }

        $this->table(['District', 'Config', 'Chat target', 'Duty thread', 'Eligible', 'Reason'], $rows);
        $this->line('Target presence is checked locally; Telegram delivery is not tested.');

        return self::SUCCESS;
    }
}
