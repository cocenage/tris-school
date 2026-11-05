<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('effective_from')->index();
            $table->string('version_type', 40)->default('schedule_change');
            $table->date('employment_start_date')->nullable();
            $table->json('weekend_days')->nullable();
            $table->json('previous_values')->nullable();
            $table->json('new_values');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['user_id', 'effective_from', 'id'], 'work_schedule_user_effective_idx');
        });

        $activationDate = now()->toDateString();
        $timestamp = now();

        DB::table('users')
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->where('status', 'approved')
            ->where('is_active', true)
            ->whereNotNull('work_started_at')
            ->whereNotNull('weekend_days')
            ->orderBy('id')
            ->get(['id', 'name', 'work_started_at', 'weekend_days'])
            ->each(function (object $user) use ($activationDate, $timestamp): void {
                $weekendDays = $this->normalizeWeekendDays($user->weekend_days);

                if ($weekendDays === null) {
                    return;
                }

                try {
                    $employmentStartDate = CarbonImmutable::parse($user->work_started_at)->toDateString();
                } catch (Throwable) {
                    return;
                }

                $newValues = [
                    'employment_start_date' => $employmentStartDate,
                    'weekend_days' => $weekendDays,
                ];

                DB::table('employee_work_schedule_versions')->insert([
                    'user_id' => $user->id,
                    'effective_from' => $activationDate,
                    'version_type' => 'activation_snapshot',
                    'employment_start_date' => $employmentStartDate,
                    'weekend_days' => json_encode($weekendDays, JSON_THROW_ON_ERROR),
                    'previous_values' => null,
                    'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR),
                    'actor_id' => null,
                    'actor_name_snapshot' => null,
                    'reason' => 'Снимок графика на дату запуска календаря',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_work_schedule_versions')
            && DB::table('employee_work_schedule_versions')->exists()) {
            throw new RuntimeException('Schedule history contains data and must be preserved during rollback.');
        }

        Schema::dropIfExists('employee_work_schedule_versions');
    }

    private function normalizeWeekendDays(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $items = $decoded;
        } else {
            $items = explode(',', $value);
        }

        $days = [];

        foreach ($items as $item) {
            if (! is_numeric($item)) {
                return null;
            }

            $day = (int) $item;

            if ($day < 1 || $day > 7) {
                return null;
            }

            $days[] = $day;
        }

        return array_values(array_unique($days));
    }
};
