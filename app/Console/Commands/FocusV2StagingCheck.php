<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class FocusV2StagingCheck extends Command
{
    protected $signature = 'focus-v2:staging-check {--json : Machine-readable readiness report}';

    protected $description = 'Read-only safety preflight for a separate Focus V2 staging instance';

    public function handle(): int
    {
        $url = (string) config('app.url', '');
        $hostname = strtolower((string) parse_url($url, PHP_URL_HOST));
        $databaseName = (string) config('database.default', '');
        $database = (array) config('database.connections.'.$databaseName, []);
        $configuredDatabase = trim((string) ($database['database'] ?? ''));
        $configuredUrl = trim((string) ($database['url'] ?? ''));
        $appKey = (string) config('app.key', '');

        $checks = [
            'environment_is_staging' => app()->environment('staging'),
            'focus_v2_enabled' => config('focus-v2.enabled', false) === true,
            'https_app_url' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https',
            'non_production_host' => $hostname !== ''
                && ! in_array($hostname, ['trisservice.eu', 'academy.trisservice.eu'], true),
            'debug_disabled' => config('app.debug', false) === false,
            'app_key_configured' => $appKey !== '',
            'database_configured' => $databaseName !== ''
                && ($configuredUrl !== '' || ($configuredDatabase !== '' && $configuredDatabase !== ':memory:')),
            'queue_sync' => config('queue.default') === 'sync',
            'mail_log' => config('mail.default') === 'log',
        ];

        $passed = ! in_array(false, $checks, true);
        if ($this->option('json')) {
            $this->line(json_encode(['ready' => $passed, 'checks' => $checks],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->info('Focus V2 staging — read-only preflight');
            foreach ($checks as $name => $success) {
                $this->line(sprintf('%s %s', $success ? 'PASS' : 'FAIL', $name));
            }
            $this->line('MANUAL: verify staging database/storage are separate from production.');
            $this->line('MANUAL: verify Telegram bot token, chat IDs, webhooks, scheduler and workers are test-only or disabled.');
            $this->line('MANUAL: verify HTTPS, writable storage and free space before large video tests.');
            if (! $passed) {
                $this->components->error('Staging safety checks failed. Do not enable a public preview.');
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
