<?php

namespace App\Console\Commands;

use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportOldWeekendRequests extends Command
{
    protected $signature = 'import:old-weekends {--dry-run : Только показать, без записи в БД}';

    protected $description = 'Import old days table into new day off requests tables';

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
            ->table('days')
            ->orderBy('id')
            ->get();

        $this->info('Найдено старых заявок выходного: ' . $rows->count());

        $createdRequests = 0;
        $createdDays = 0;
        $skippedDays = 0;

        foreach ($rows as $old) {
            $oldUser = DB::connection('old_sqlite')
                ->table('users')
                ->where('id', $old->user_id)
                ->first();

            if (! $oldUser) {
                $this->warn("Пропуск old day #{$old->id}: старый пользователь {$old->user_id} не найден");
                continue;
            }

            $email = $oldUser->email ?: "old_user_{$oldUser->id}@import.local";

            $newUser = DB::table('users')
                ->where('email', $email)
                ->first();

            if (! $newUser) {
                $newUserId = DB::table('users')->insertGetId([
                    'name' => $oldUser->name ?: "Импортированный пользователь #{$oldUser->id}",
                    'email' => $email,
                    'password' => bcrypt(Str::random(32)),
                    'role' => $oldUser->role ?? 'cleaner',
                    'status' => 'approved',
                    'approved_at' => now(),
                    'is_active' => $oldUser->is_active ?? 1,
                    'dip' => $oldUser->dip ?? 0,
                    'birthday' => $oldUser->birthday ?? null,
                    'work_started_at' => $oldUser->work_started_at ?? null,
                    'created_at' => $oldUser->created_at ?? now(),
                    'updated_at' => now(),
                ]);

                $this->info("Создан пользователь-болванка: {$oldUser->name} / {$email}");
            } else {
                $newUserId = $newUser->id;
            }

            $fromRaw = $old->date_from ?: $old->when;
            $toRaw = $old->date_to ?: $old->when;

            $from = Carbon::parse($fromRaw)->startOfDay();
            $to = Carbon::parse($toRaw)->startOfDay();

            if ($to->lt($from)) {
                $this->warn("Пропуск old day #{$old->id}: date_to меньше date_from");
                continue;
            }

            $dates = collect(CarbonPeriod::create($from, $to))
                ->map(fn ($date) => $date->format('Y-m-d'));

            $freeDates = $dates->filter(function ($date) use ($newUserId) {
                return ! DayOffRequestDay::query()
                    ->where('user_id', $newUserId)
                    ->whereDate('date', $date)
                    ->exists();
            })->values();

            if ($freeDates->isEmpty()) {
                $skippedDays += $dates->count();
                $this->line("old day #{$old->id}: все даты уже есть, пропуск");
                continue;
            }

            if ($dryRun) {
                $this->line("DRY old day #{$old->id}: old user {$old->user_id} → new user {$newUserId}, {$from->toDateString()} — {$to->toDateString()}, дней к импорту: {$freeDates->count()}");
                continue;
            }

            DB::transaction(function () use ($old, $newUserId, $freeDates, &$createdRequests, &$createdDays, &$skippedDays, $dates) {
                $request = DayOffRequest::query()->create([
                    'user_id' => $newUserId,
                    'reason' => $old->reason ?: 'Перенесено со старого сайта',
                    'status' => 'approved',
                    'submitted_at' => $old->created_at,
                    'reviewed_at' => $old->created_at,
                    'created_at' => $old->created_at,
                    'updated_at' => $old->updated_at,
                ]);

                $createdRequests++;

                foreach ($freeDates as $date) {
                    $date = Carbon::parse($date)->format('Y-m-d');

                    $exists = DayOffRequestDay::query()
                        ->where('user_id', $newUserId)
                        ->whereDate('date', $date)
                        ->exists();

                    if ($exists) {
                        $skippedDays++;
                        continue;
                    }

                    DayOffRequestDay::query()->create([
                        'day_off_request_id' => $request->id,
                        'user_id' => $newUserId,
                        'date' => $date,
                        'status' => 'approved',
                        'created_at' => $old->created_at,
                        'updated_at' => $old->updated_at,
                    ]);

                    $createdDays++;
                }

                $skippedDays += $dates->count() - $freeDates->count();
            });
        }

        $this->newLine();
        $this->info("Создано заявок выходного: {$createdRequests}");
        $this->info("Создано дней: {$createdDays}");
        $this->info("Пропущено дней-дубликатов: {$skippedDays}");

        return self::SUCCESS;
    }
}