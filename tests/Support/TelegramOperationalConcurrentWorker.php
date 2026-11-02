<?php

use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
[$script, $databasePath, $gatePath, $readyPath, $messageId] = $argv;

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.connections.analytics.driver' => 'sqlite',
    'database.connections.analytics.database' => $databasePath,
    'database.connections.analytics.url' => null,
    'services.telegram.operational_chat_ids' => ['-1001'],
    'services.telegram.work_allowed_chat_ids' => [],
]);
DB::purge('analytics');

$message = TelegramMessage::query()->findOrFail((int) $messageId);

if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Could not create the worker-ready marker.');
}

$deadline = microtime(true) + 20;

while (! is_file($gatePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the concurrent-worker barrier.');
    }

    usleep(10_000);
}

$result = app(TelegramOperationalEventObserver::class)->observe($message, sourceBot: 'analytics');

fwrite(STDOUT, json_encode([
    'outcome' => $result['outcome'] ?? null,
    'idempotent_reuse' => $result['idempotent_reuse'] ?? null,
], JSON_THROW_ON_ERROR));
