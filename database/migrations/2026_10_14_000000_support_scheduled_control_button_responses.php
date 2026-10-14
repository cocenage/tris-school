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
            $table->unsignedBigInteger('telegram_message_record_id')->nullable()->change();
            $table->string('telegram_message_id')->nullable()->change();
            $table->string('telegram_callback_query_id')->nullable()->unique('scheduled_response_callback_once');
        });
    }

    public function down(): void
    {
        if (DB::table('telegram_scheduled_message_responses')->whereNotNull('telegram_callback_query_id')
            ->orWhereNull('telegram_message_record_id')->orWhereNull('telegram_message_id')->exists()) {
            throw new RuntimeException('Cannot roll back scheduled-control callback responses while callback rows exist.');
        }

        Schema::table('telegram_scheduled_message_responses', function (Blueprint $table): void {
            $table->dropUnique('scheduled_response_callback_once');
            $table->dropColumn('telegram_callback_query_id');
            $table->unsignedBigInteger('telegram_message_record_id')->nullable(false)->change();
            $table->string('telegram_message_id')->nullable(false)->change();
        });
    }
};
