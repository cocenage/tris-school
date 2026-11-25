<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_questions', function (Blueprint $table): void {
            $table->string('telegram_source_chat_id')->nullable();
            $table->unsignedBigInteger('telegram_source_thread_id')->nullable();
            $table->unsignedBigInteger('telegram_source_message_id')->nullable();
            $table->unsignedInteger('decision_version')->default(0);
        });

        Schema::create('schedule_question_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_question_id')->constrained()->cascadeOnDelete();
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
            $table->unique(['schedule_question_id', 'version']);
        });
        Schema::table('feedback_suggestions', function (Blueprint $table): void {
            $table->string('telegram_source_chat_id')->nullable();
            $table->unsignedBigInteger('telegram_source_thread_id')->nullable();
            $table->unsignedBigInteger('telegram_source_message_id')->nullable();
            $table->unsignedInteger('decision_version')->default(0);
        });

        Schema::create('feedback_suggestion_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_suggestion_id')->constrained()->cascadeOnDelete();
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
            $table->unique(['feedback_suggestion_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_suggestion_decisions');
        Schema::table('feedback_suggestions', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_source_chat_id', 'telegram_source_thread_id',
                'telegram_source_message_id', 'decision_version',
            ]);
        });
        Schema::dropIfExists('schedule_question_decisions');
        Schema::table('schedule_questions', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_source_chat_id', 'telegram_source_thread_id',
                'telegram_source_message_id', 'decision_version',
            ]);
        });
    }
};
