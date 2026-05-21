<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table) {
    $table->id();

    $table->foreignId('telegram_chat_id')
        ->constrained('telegram_chats')
        ->cascadeOnDelete();

    $table->foreignId('telegram_topic_id')
        ->nullable()
        ->constrained('telegram_topics')
        ->nullOnDelete();

    $table->foreignId('telegram_user_id')
        ->nullable()
        ->constrained('telegram_users')
        ->nullOnDelete();

    $table->string('message_id');
    $table->string('message_type')->default('unknown');

    $table->text('text')->nullable();
    $table->text('caption')->nullable();

    $table->timestamp('sent_at')->nullable()->index();
    $table->timestamp('edited_at')->nullable();

    $table->json('raw')->nullable();

    $table->timestamps();

    $table->unique(['telegram_chat_id', 'message_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
