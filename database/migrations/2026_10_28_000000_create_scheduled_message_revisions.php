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
        Schema::create('telegram_scheduled_message_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheduled_message_id');
            $table->dateTime('effective_at_utc');
            $table->boolean('baseline')->default(false);
            $table->string('control_type')->nullable();
            $table->string('send_time');
            $table->text('weekdays');
            $table->boolean('enabled');
            $table->boolean('is_deleted');
            $table->string('name');
            $table->unsignedBigInteger('telegram_chat_record_id');
            $table->unsignedBigInteger('telegram_topic_record_id')->nullable();
            $table->index(['scheduled_message_id', 'effective_at_utc'], 'scheduled_message_revision_time');
        });

        $at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        foreach (DB::table('telegram_scheduled_messages')->orderBy('id')->get() as $message) {
            DB::table('telegram_scheduled_message_revisions')->insert([
                'scheduled_message_id' => $message->id,
                'effective_at_utc' => $at,
                'baseline' => true,
                'control_type' => $message->control_type,
                'send_time' => $message->send_time,
                'weekdays' => $message->weekdays,
                'enabled' => $message->enabled,
                'is_deleted' => $message->deleted_at !== null,
                'name' => $message->name,
                'telegram_chat_record_id' => $message->telegram_chat_record_id,
                'telegram_topic_record_id' => $message->telegram_topic_record_id,
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('telegram_scheduled_message_revisions')->where('baseline', false)->exists()) {
            throw new RuntimeException('Cannot remove recorded scheduled message configuration changes.');
        }
        Schema::dropIfExists('telegram_scheduled_message_revisions');
    }
};
