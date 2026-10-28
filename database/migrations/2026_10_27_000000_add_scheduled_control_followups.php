<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_scheduled_messages', function (Blueprint $table): void {
            $table->boolean('followup_repeat_enabled')->default(true);
            $table->unsignedSmallInteger('followup_interval_minutes')->default(60);
            $table->unsignedTinyInteger('followup_max_repeats')->default(2);
            $table->boolean('followup_reminder_enabled')->default(true);
            $table->unsignedSmallInteger('followup_reminder_minutes')->default(20);
            $table->time('followup_cutoff')->nullable();
        });

        Schema::table('telegram_scheduled_message_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('trigger_delivery_id')->nullable()->index();
            $table->dateTime('sent_at_utc')->nullable();
        });

        Schema::create('telegram_scheduled_control_followups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('telegram_scheduled_message_deliveries')->restrictOnDelete();
            $table->string('district_key', 32);
            $table->string('kind', 16);
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->dateTime('due_at');
            $table->dateTime('due_at_utc')->nullable();
            $table->dateTime('dispatch_requested_at_utc')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('sent_at_utc')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['delivery_id', 'district_key', 'kind', 'sequence'], 'scheduled_followup_once');
            $table->index(['status', 'due_at']);
        });

        Schema::table('telegram_scheduled_message_responses', function (Blueprint $table): void {
            // Added without a SQLite table rebuild; the application validates this link.
            $table->unsignedBigInteger('followup_id')->nullable()->index();
            $table->dateTime('responded_at_utc')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('telegram_scheduled_control_followups')->exists()) {
            throw new RuntimeException('Cannot remove recorded scheduled control follow-ups.');
        }

        Schema::table('telegram_scheduled_message_responses', function (Blueprint $table): void {
            $table->dropIndex(['followup_id']);
            $table->dropColumn(['followup_id', 'responded_at_utc']);
        });
        Schema::dropIfExists('telegram_scheduled_control_followups');
        Schema::table('telegram_scheduled_message_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['trigger_delivery_id']);
            $table->dropColumn(['trigger_delivery_id', 'sent_at_utc']);
        });
        Schema::table('telegram_scheduled_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'followup_repeat_enabled', 'followup_interval_minutes', 'followup_max_repeats',
                'followup_reminder_enabled', 'followup_reminder_minutes', 'followup_cutoff',
            ]);
        });
    }
};
