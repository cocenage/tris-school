<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_scheduled_message_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('telegram_scheduled_message_deliveries')->restrictOnDelete();
            $table->unsignedBigInteger('telegram_message_record_id'); // analytics reference, no cross-DB FK
            $table->string('chat_id');
            $table->string('message_thread_id')->nullable();
            $table->string('telegram_message_id');
            $table->string('reply_to_message_id');
            $table->string('telegram_user_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('author_name')->nullable();
            $table->string('username')->nullable();
            $table->text('text');
            $table->dateTime('responded_at');
            $table->unsignedInteger('response_latency_seconds')->nullable();
            $table->string('classification', 20);
            $table->string('classification_reason')->nullable();
            $table->timestamps();
            $table->unique(['chat_id', 'telegram_message_id'], 'scheduled_response_once');
            $table->index(['delivery_id', 'responded_at']);
        });
        Schema::table('telegram_scheduled_message_deliveries', function (Blueprint $table): void {
            $table->index(['chat_id', 'telegram_message_id'], 'scheduled_reply_lookup');
        });
        Schema::create('telegram_scheduled_control_summary_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->date('summary_date')->index();
            $table->string('chat_id');
            $table->string('message_thread_id')->nullable();
            $table->string('delivery_key', 64)->unique();
            $table->string('summary_hash', 64);
            $table->json('summary');
            $table->text('text');
            $table->string('status', 20)->default('queued');
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_scheduled_control_summary_deliveries');
        Schema::dropIfExists('telegram_scheduled_message_responses');
        Schema::table('telegram_scheduled_message_deliveries', function (Blueprint $table): void {
            $table->dropIndex('scheduled_reply_lookup');
        });
    }
};
