<?php

namespace App\Console\Commands;

use App\Models\TrisMareSnapshot;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncTrisMareSnapshots extends Command
{
    protected $signature = 'tris-mare:sync';

    protected $description = 'Sync TRIS Mare snapshots from Google Sheets CSV';

    public function handle(): int
    {
        $sheetId = env('TRIS_MARE_SHEET_ID');
        $summaryGid = env('TRIS_MARE_SUMMARY_GID');
        $dailyGid = env('TRIS_MARE_DAILY_GID');

        if (! $sheetId || ! $summaryGid || ! $dailyGid) {
            $this->error('Не заполнены TRIS_MARE_SHEET_ID / TRIS_MARE_SUMMARY_GID / TRIS_MARE_DAILY_GID');

            return self::FAILURE;
        }

        $summaryRows = $this->fetchCsv($sheetId, $summaryGid);
        $dailyRows = $this->fetchCsv($sheetId, $dailyGid);

        $dailyHistoryByUserId = $this->buildDailyHistory($dailyRows);

        $imported = 0;

        foreach ($summaryRows as $row) {
            $employeeId = $this->cell($row, 0); // A: ID сотрудника

            if ($employeeId === '' || ! is_numeric($employeeId)) {
                continue;
            }

            $user = User::find((int) $employeeId);

            $employeeName = $this->cell($row, 1); // B: Сотрудник

            if ($employeeName === '') {
                continue;
            }

            TrisMareSnapshot::updateOrCreate(
                [
                    'employee_external_id' => (string) $employeeId,
                ],
                [
                    'user_id' => $user?->id,
                    'employee_name' => $employeeName,

                    'daily_points' => $this->intCell($row, 2), // C
                    'weekly_points' => $this->intCell($row, 3), // D
                    'total_points' => $this->intCell($row, 4), // E
                    'left_to_230' => $this->intCell($row, 5), // F
                    'status' => $this->cell($row, 6), // G
                    'progress_percent' => $this->percentCell($row, 7), // H
                    'comment' => $this->cell($row, 8), // I
                    'working_days' => $this->intCell($row, 9), // J
                    'rating' => $this->intCell($row, 12), // M

                    'daily_history' => $dailyHistoryByUserId[$employeeId] ?? [],
                    'raw_data' => $row,
                    'synced_at' => now(),
                ]
            );

            $imported++;
        }

        $this->info("TRIS Mare импортирован. Обновлено: {$imported}");

        return self::SUCCESS;
    }

    protected function fetchCsv(string $sheetId, string $gid): array
    {
        $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Не удалось загрузить CSV gid={$gid}");
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($response->body()));

        return collect($lines)
            ->map(fn (string $line) => str_getcsv($line))
            ->values()
            ->all();
    }

    protected function buildDailyHistory(array $rows): array
    {
        $history = [];

        foreach ($rows as $row) {
            $date = $this->cell($row, 0); // A: Дата
            $employeeId = $this->cell($row, 3); // D: ID сотрудника
            $points = $this->intCell($row, 12); // M: Баллы за день
            $comment = $this->cell($row, 13); // N: Комментарий

            if ($employeeId === '' || ! is_numeric($employeeId)) {
                continue;
            }

            if ($date === '') {
                continue;
            }

            $history[$employeeId][] = [
                'date' => $date,
                'points' => $points,
                'comment' => $comment,
            ];
        }

        foreach ($history as $employeeId => $items) {
            $history[$employeeId] = collect($items)
                ->reverse()
                ->take(10)
                ->values()
                ->all();
        }

        return $history;
    }

    protected function cell(array $row, int $index): string
    {
        return trim((string) ($row[$index] ?? ''));
    }

    protected function intCell(array $row, int $index): int
    {
        $value = $this->cell($row, $index);

        $value = Str::of($value)
            ->replace('%', '')
            ->replace(',', '.')
            ->replace(' ', '')
            ->toString();

        if ($value === '') {
            return 0;
        }

        return (int) round((float) $value);
    }

    protected function percentCell(array $row, int $index): int
    {
        return $this->intCell($row, $index);
    }
}