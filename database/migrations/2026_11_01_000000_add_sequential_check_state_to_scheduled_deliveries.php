<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'criteria_hash' => fn (Blueprint $table) => $table->string('criteria_hash', 64)->nullable(),
            'sequence_context' => fn (Blueprint $table) => $table->json('sequence_context')->nullable(),
            'render_hash' => fn (Blueprint $table) => $table->string('render_hash', 64)->nullable(),
        ];

        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('telegram_scheduled_message_deliveries', $column)) {
                Schema::table('telegram_scheduled_message_deliveries', $add);
            }
        }
    }

    public function down(): void
    {
        // These nullable columns are intentionally retained on rollback so older code can
        // continue running on SQLite versions that cannot safely drop table columns.
    }
};
