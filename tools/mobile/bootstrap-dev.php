<?php

// CLI-only bootstrap for a disposable, USB-connected development backend.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

$directory = storage_path('app/mobile-dev');
$expected = [
    'sqlite' => $directory.'/primary.sqlite',
    'analytics' => $directory.'/analytics.sqlite',
    'old_sqlite' => $directory.'/old.sqlite',
    'sqlite_queue' => $directory.'/queue.sqlite',
];

if (! $app->environment('mobiledev')
    || config('app.url') !== 'http://127.0.0.1:8765'
    || config('database.default') !== 'sqlite'
    || config('queue.default') !== 'sync'
    || config('session.driver') !== 'database'
    || config('services.telegram.bot_token')
    || config('services.telegram.scheduled_bot_token')
    || config('services.telegram.admin_chat_id')) {
    throw new RuntimeException('Refusing to bootstrap outside the isolated mobile development environment.');
}

foreach ($expected as $connection => $path) {
    $configured = config("database.connections.{$connection}.database");
    if (! is_string($configured)
        || ! is_file($path)
        || realpath($configured) !== realpath($path)
        || config("database.connections.{$connection}.url")) {
        throw new RuntimeException("Unsafe mobile development connection: {$connection}");
    }
}

$base = [
    '2026_05_21_082853_create_telegram_chats_table.php',
    '2026_05_21_082854_create_telegram_topics_table.php',
    '2026_05_21_082855_create_telegram_users_table.php',
    '2026_05_21_082857_create_telegram_messages_table.php',
    '2026_05_21_082906_create_telegram_attachments_table.php',
    '2026_05_21_101806_fix_telegram_chats_table.php',
];

$paths = array_map(fn ($file) => 'database/migrations/'.$file, $base);
foreach ([
    ['--database' => 'analytics', '--path' => $paths, '--force' => true],
    ['--database' => 'sqlite', '--force' => true],
] as $options) {
    $status = Artisan::call('migrate', $options);
    echo Artisan::output();
    if ($status !== 0) {
        throw new RuntimeException('Mobile development migration failed.');
    }
}

// Existing Filament login is used only for the synthetic QA administrator.
// No new authentication route, bypass, or production account is introduced.
$password = Str::random(28);
$user = User::query()->firstOrNew(['email' => 'mobile-qa@example.invalid']);
$user->forceFill([
    'name' => 'TRIS QA Administrator',
    'role' => 'admin',
    'status' => 'approved',
    'is_active' => true,
    'email_verified_at' => now(),
    'password' => $password,
]);

if ($user->exists) {
    $user->saveQuietly();
} else {
    $user->save();
}

echo PHP_EOL."Local QA admin: mobile-qa@example.invalid".PHP_EOL;
echo "Password: {$password}".PHP_EOL;
echo "TRIS ID: {$user->tris_id}".PHP_EOL;
echo "Admin login: http://127.0.0.1:8765/admin/login".PHP_EOL;
echo "Approve device: http://127.0.0.1:8765/profile/devices".PHP_EOL;
