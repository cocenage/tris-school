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
        Schema::create('telegram_topics', function (Blueprint $table) {
    $table->id();

    $table->foreignId('telegram_chat_id')
        ->constrained('telegram_chats')
        ->cascadeOnDelete();

    $table->string('telegram_thread_id');
    $table->string('title')->nullable();

    $table->string('purpose')->nullable();
    $table->boolean('is_enabled')->default(true);

    $table->timestamps();

    $table->unique(['telegram_chat_id', 'telegram_thread_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_topics');
    }
};
