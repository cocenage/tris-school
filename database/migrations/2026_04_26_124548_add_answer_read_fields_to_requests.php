<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ([
            'day_off_requests',
            'vacation_requests',
            'inventory_requests',
            'salary_questions',
            'schedule_questions',
            'feedback_suggestions',
        ] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('answered_at')->nullable()->after('admin_comment');
                $table->timestamp('answer_seen_at')->nullable()->after('answered_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'day_off_requests',
            'vacation_requests',
            'inventory_requests',
            'salary_questions',
            'schedule_questions',
            'feedback_suggestions',
        ] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['answered_at', 'answer_seen_at']);
            });
        }
    }
};
