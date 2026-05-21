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
        Schema::create('telegram_attachments', function (Blueprint $table) {
    $table->id();

    $table->foreignId('telegram_message_id')
        ->constrained('telegram_messages')
        ->cascadeOnDelete();

    $table->string('type');
    $table->string('file_id');
    $table->string('file_unique_id')->nullable();

    $table->string('mime_type')->nullable();
    $table->string('file_name')->nullable();
    $table->unsignedBigInteger('file_size')->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_attachments');
    }
};
