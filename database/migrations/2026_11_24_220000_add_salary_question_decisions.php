<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_questions', function (Blueprint $table): void {
            $table->string('telegram_source_chat_id')->nullable();
            $table->unsignedBigInteger('telegram_source_thread_id')->nullable();
            $table->unsignedBigInteger('telegram_source_message_id')->nullable();
            $table->unsignedInteger('decision_version')->default(0);
        });

        Schema::create('salary_question_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('status');
            $table->text('comment');
            $table->string('source');
            $table->string('delivery_status')->default('pending');
            $table->string('card_sync_status')->default('pending');
            $table->unsignedTinyInteger('card_sync_attempts')->default(0);
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamps();
            $table->unique(['salary_question_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_question_decisions');
        Schema::table('salary_questions', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_source_chat_id', 'telegram_source_thread_id',
                'telegram_source_message_id', 'decision_version',
            ]);
        });
    }
};
