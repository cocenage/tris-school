<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_scheduled_message_responses', function (Blueprint $table): void {
            $table->string('reply_to_message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('telegram_scheduled_message_responses')->whereNull('reply_to_message_id')->exists()) {
            throw new RuntimeException('Cannot roll back nullable reply references while fallback response rows exist.');
        }

        Schema::table('telegram_scheduled_message_responses', function (Blueprint $table): void {
            $table->string('reply_to_message_id')->nullable(false)->change();
        });
    }
};
