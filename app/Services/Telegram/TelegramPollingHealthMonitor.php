<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramPollingHealthMonitor
{
    private const CACHE_STORE = 'file';

    private const CACHE_PREFIX = 'telegram:polling-health:v1:';

    private const RETENTION_SECONDS = 86400;

    private const ISSUE_WINDOW_SECONDS = 900;

    private const MAX_ISSUES = 32;

    /**
     * Staleness windows allow several missed scheduler ticks without calling
     * a bot unavailable during one ordinary delayed run.
     *
     * @var array<string, array{label: string, stale_after_seconds: int}>
     */
    private const POLLERS = [
        'work' => ['label' => 'Work Bot', 'stale_after_seconds' => 600],
        'analytics' => ['label' => 'Analytics Bot', 'stale_after_seconds' => 600],
        'scheduled' => ['label' => 'Scheduled Bot', 'stale_after_seconds' => 1200],
    ];

    public function recordSuccess(string $poller, int $durationMs, int $processed, int $connectionRetries = 0): void
    {
        $this->mutate($poller, function (array $state, int $now) use ($durationMs, $processed, $connectionRetries): array {
            $state['last_attempt_at'] = $now;
            $state['last_success_at'] = $now;
            $state['last_duration_ms'] = max(0, $durationMs);
            $state['last_processed'] = max(0, $processed);
            $state['last_connection_retries'] = max(0, $connectionRetries);
            $state['consecutive_failures'] = 0;

            if ($connectionRetries > 0) {
                $state['last_failure_at'] = $now;
                $state['last_failure_stage'] = 'connection_retry';
                $state['last_exception'] = 'ConnectionException';
                $this->appendIssue($state, $now, 'connection_retry');
            }

            return $state;
        });
    }

    public function recordFailure(
        string $poller,
        string $stage,
        string $exceptionType,
        int $durationMs,
        int $connectionRetries = 0,
        ?int $connectionErrorCode = null,
    ): void {
        $stage = in_array($stage, ['fetch', 'acknowledge', 'process', 'configuration', 'lock'], true)
            ? $stage
            : 'other';
        $exceptionType = preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $exceptionType)
            ? class_basename($exceptionType)
            : 'UnknownException';

        $connectionErrorCode = $connectionErrorCode !== null && $connectionErrorCode > 0 && $connectionErrorCode < 1000
            ? $connectionErrorCode
            : null;

        $this->mutate($poller, function (array $state, int $now) use ($stage, $exceptionType, $durationMs, $connectionRetries, $connectionErrorCode): array {
            $state['last_attempt_at'] = $now;
            $state['last_failure_at'] = $now;
            $state['last_failure_stage'] = $stage;
            $state['last_exception'] = $exceptionType;
            $state['last_duration_ms'] = max(0, $durationMs);
            $state['last_connection_retries'] = max(0, $connectionRetries);
            $state['last_connection_error_code'] = $connectionErrorCode;
            $state['consecutive_failures'] = max(0, (int) ($state['consecutive_failures'] ?? 0)) + 1;
            $this->appendIssue($state, $now, 'failure');

            return $state;
        });
    }

    /**
     * @return array{work: array<string, mixed>, analytics: array<string, mixed>, scheduled: array<string, mixed>}
     */
    public function all(): array
    {
        return [
            'work' => $this->snapshot('work'),
            'analytics' => $this->snapshot('analytics'),
            'scheduled' => $this->snapshot('scheduled'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $poller): array
    {
        $profile = self::POLLERS[$poller] ?? null;

        if ($profile === null) {
            return $this->unknownSnapshot($poller);
        }

        try {
            $state = Cache::store(self::CACHE_STORE)->get(self::CACHE_PREFIX.$poller);
        } catch (Throwable $error) {
            $this->reportStorageFailure($poller, $error);
            $state = null;
        }

        if (! is_array($state)) {
            return $this->unknownSnapshot($poller, $profile);
        }

        $now = now()->getTimestamp();
        $lastSuccessAt = $this->timestamp($state['last_success_at'] ?? null);
        $successAge = $lastSuccessAt === null ? null : max(0, $now - $lastSuccessAt);
        $stale = $successAge === null || $successAge > $profile['stale_after_seconds'];
        $recentIssues = array_values(array_filter(
            is_array($state['recent_issues'] ?? null) ? $state['recent_issues'] : [],
            fn (mixed $issue): bool => is_array($issue)
                && $this->timestamp($issue['at'] ?? null) !== null
                && $now - (int) $issue['at'] <= self::ISSUE_WINDOW_SECONDS,
        ));

        $status = $stale
            ? ($this->timestamp($state['last_attempt_at'] ?? null) === null ? 'unknown' : 'unavailable')
            : ($recentIssues === [] ? 'working' : 'partially_degraded');

        return [
            'poller' => $poller,
            'label' => $profile['label'],
            'status' => $status,
            'last_attempt_at' => $this->timestamp($state['last_attempt_at'] ?? null),
            'last_success_at' => $lastSuccessAt,
            'last_failure_at' => $this->timestamp($state['last_failure_at'] ?? null),
            'last_failure_stage' => is_string($state['last_failure_stage'] ?? null) ? $state['last_failure_stage'] : null,
            'last_exception' => is_string($state['last_exception'] ?? null) ? $state['last_exception'] : null,
            'last_connection_error_code' => isset($state['last_connection_error_code']) && (int) $state['last_connection_error_code'] > 0 ? (int) $state['last_connection_error_code'] : null,
            'recent_issues' => count($recentIssues),
            'last_duration_ms' => isset($state['last_duration_ms']) ? max(0, (int) $state['last_duration_ms']) : null,
            'last_processed' => isset($state['last_processed']) ? max(0, (int) $state['last_processed']) : null,
            'last_connection_retries' => max(0, (int) ($state['last_connection_retries'] ?? 0)),
            'stale_after_seconds' => $profile['stale_after_seconds'],
        ];
    }

    private function mutate(string $poller, callable $callback): void
    {
        if (! isset(self::POLLERS[$poller])) {
            return;
        }

        try {
            $cache = Cache::store(self::CACHE_STORE);
            $key = self::CACHE_PREFIX.$poller;
            $state = $cache->get($key);
            $state = $callback(is_array($state) ? $state : [], now()->getTimestamp());
            $cache->put($key, $state, self::RETENTION_SECONDS);
        } catch (Throwable $error) {
            $this->reportStorageFailure($poller, $error);
        }
    }

    private function appendIssue(array &$state, int $now, string $kind): void
    {
        $issues = is_array($state['recent_issues'] ?? null) ? $state['recent_issues'] : [];
        $issues[] = ['at' => $now, 'kind' => $kind];

        $state['recent_issues'] = array_slice(array_values(array_filter(
            $issues,
            fn (mixed $issue): bool => is_array($issue)
                && $this->timestamp($issue['at'] ?? null) !== null
                && $now - (int) $issue['at'] <= self::ISSUE_WINDOW_SECONDS,
        )), -self::MAX_ISSUES);
    }

    private function timestamp(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }

    private function unknownSnapshot(string $poller, ?array $profile = null): array
    {
        return [
            'poller' => $poller,
            'label' => $profile['label'] ?? 'Unknown polling client',
            'status' => 'unknown',
            'last_attempt_at' => null,
            'last_success_at' => null,
            'last_failure_at' => null,
            'last_failure_stage' => null,
            'last_exception' => null,
            'last_connection_error_code' => null,
            'recent_issues' => 0,
            'last_duration_ms' => null,
            'last_processed' => null,
            'last_connection_retries' => 0,
            'stale_after_seconds' => $profile['stale_after_seconds'] ?? null,
        ];
    }

    private function reportStorageFailure(string $poller, Throwable $error): void
    {
        try {
            Log::warning('Telegram polling health state unavailable', [
                'poller' => $poller,
                'exception' => class_basename($error),
            ]);
        } catch (Throwable) {
            // Health telemetry must never interrupt Telegram polling.
        }
    }
}
