<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_calendar_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('operation', 40);
            $table->string('category', 40)->nullable();
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->string('supersedes_request_type', 80)->nullable();
            $table->unsignedBigInteger('supersedes_request_day_id')->nullable();
            $table->date('supersedes_request_date')->nullable();
            $table->date('replacement_date')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable();
            $table->foreignId('reverses_adjustment_id')->nullable()
                ->constrained('employee_calendar_adjustments')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on'], 'calendar_adjustment_user_dates_idx');
            $table->index(
                ['supersedes_request_type', 'supersedes_request_day_id', 'supersedes_request_date'],
                'calendar_adjustment_request_day_idx',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_calendar_adjustments')
            && DB::table('employee_calendar_adjustments')->exists()) {
            throw new RuntimeException('Calendar correction history contains data and must be preserved during rollback.');
        }

        Schema::dropIfExists('employee_calendar_adjustments');
    }
};
