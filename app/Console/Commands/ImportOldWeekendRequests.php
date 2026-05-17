<?php

namespace App\Console\Commands;

use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportOldWeekendRequests extends Command
{
    protected $signature = 'import:old-weekends {--dry-run : Только показать, без записи в БД}';

    protected $description = 'Import old weekends table into new day off requests tables';

    public function handle(): int
    {
        $oldDb = database_path('old.sqlite');

        if (! file_exists($oldDb)) {
            $this->error("Не найден файл старой базы: {$oldDb}");
            return self::FAILURE;
        }

        config([
            'database.connections.old_sqlite' => [
                'driver' => 'sqlite',
                'database' => $oldDb,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        $dryRun = (bool) $this->option('dry-run');

        $rows = DB::connection('old_sqlite')
            ->table('weekends')
            ->orderBy('id')
            ->get();

        $this->info('Найдено старых заявок: ' . $rows->count());

        $createdRequests = 0;
        $createdDays = 0;
        $skippedDays = 0;

        foreach ($rows as $old) {
            $from = Carbon::parse($old->date_from)->startOfDay();
            $to = Carbon::parse($old->date_to)->startOfDay();

            if ($to->lt($from)) {
                $this->warn("Пропуск old weekend #{$old->id}: date_to меньше date_from");
                continue;
            }

            $dates = collect(CarbonPeriod::create($from, $to))
                ->map(fn ($date) => $date->format('Y-m-d'));

            $freeDates = $dates->filter(function ($date) use ($old) {
                return ! DayOffRequestDay::query()
                    ->where('user_id', $old->user_id)
                    ->where('date', $date)
                    ->exists();
            })->values();

            if ($freeDates->isEmpty()) {
                $skippedDays += $dates->count();
                $this->line("old weekend #{$old->id}: все даты уже есть, пропуск");
                continue;
            }

            if ($dryRun) {
                $this->line("DRY old #{$old->id}: user {$old->user_id}, {$from->toDateString()} — {$to->toDateString()}, дней к импорту: {$freeDates->count()}");
                continue;
            }

            DB::transaction(function () use ($old, $freeDates, &$createdRequests, &$createdDays, &$skippedDays, $dates) {
                $request = DayOffRequest::query()->create([
                    'user_id' => $old->user_id,
                    'reason' => $old->message ?: 'Перенесено со старого сайта',
                    'status' => 'pending',
                    'submitted_at' => $old->created_at,
                    'created_at' => $old->created_at,
                    'updated_at' => $old->updated_at,
                ]);

                $createdRequests++;

                foreach ($freeDates as $date) {
                    DayOffRequestDay::query()->create([
                        'day_off_request_id' => $request->id,
                        'user_id' => $old->user_id,
                        'date' => $date,
                        'status' => 'pending',
                        'created_at' => $old->created_at,
                        'updated_at' => $old->updated_at,
                    ]);

                    $createdDays++;
                }

                $skippedDays += $dates->count() - $freeDates->count();
            });
        }

        $this->newLine();
        $this->info("Создано заявок: {$createdRequests}");
        $this->info("Создано дней: {$createdDays}");
        $this->info("Пропущено дней-дубликатов: {$skippedDays}");

        return self::SUCCESS;
    }
}