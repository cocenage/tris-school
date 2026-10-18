<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_scheduled_message_deliveries', function (Blueprint $table): void {
            $table->string('bot_type', 16)->default('scheduled');
        });

        Schema::table('telegram_scheduled_message_responses', function (Blueprint $table): void {
            $table->string('district_key', 32)->nullable();
            $table->string('response_kind', 16)->nullable();
            $table->json('response_history')->nullable();
            $table->index(['delivery_id', 'district_key', 'telegram_user_id'], 'scheduled_response_district_responder');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_scheduled_message_responses', function (Blueprint $table): void {
            $table->dropIndex('scheduled_response_district_responder');
            $table->dropColumn(['district_key', 'response_kind', 'response_history']);
        });

        Schema::table('telegram_scheduled_message_deliveries', function (Blueprint $table): void {
            $table->dropColumn('bot_type');
        });
    }
};
