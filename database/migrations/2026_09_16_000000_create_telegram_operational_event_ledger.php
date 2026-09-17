<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('analytics');

        $schema->create('telegram_operational_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->unsignedBigInteger('root_message_id');
            $table->unsignedBigInteger('telegram_chat_id');
            $table->unsignedBigInteger('telegram_topic_id')->nullable();
            $table->string('primary_type', 40);
            $table->json('types');
            $table->text('summary');
            $table->string('status', 20)->default('open');
            $table->string('confidence', 20);
            $table->text('uncertainty')->nullable();
            $table->string('subject_key')->nullable();
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('root_message_id')->references('id')->on('telegram_messages');
            $table->foreign('telegram_chat_id')->references('id')->on('telegram_chats');
            $table->foreign('telegram_topic_id')->references('id')->on('telegram_topics');
            $table->index(['telegram_chat_id', 'telegram_topic_id', 'status', 'last_observed_at'], 'telegram_operational_events_candidate_index');
            $table->index('root_message_id');
        });

        $schema->create('telegram_operational_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_message_id');
            $table->string('source_revision_hash', 64);
            $table->string('evaluation_kind', 20)->default('message');
            $table->string('state', 20)->default('processing');
            $table->string('outcome', 30)->nullable();
            $table->string('reason_code', 60)->nullable();
            $table->string('confidence', 20)->nullable();
            $table->text('uncertainty')->nullable();
            $table->timestamp('unanswered_due_at')->nullable();
            $table->boolean('is_current_revision')->default(true);
            $table->timestamp('processed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->timestamps();

            $table->foreign('telegram_message_id')->references('id')->on('telegram_messages');
            $table->unique(['telegram_message_id', 'source_revision_hash', 'evaluation_kind'], 'telegram_operational_observations_revision_unique');
            $table->index(['state', 'processed_at']);
            $table->index(['telegram_message_id', 'is_current_revision'], 'telegram_operational_observations_current_index');
            $table->index(['unanswered_due_at', 'state']);
        });

        $schema->create('telegram_operational_event_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operational_event_id');
            $table->unsignedBigInteger('observation_id');
            $table->string('role', 30);
            $table->string('transition', 20)->default('none');
            $table->string('status_before', 20)->nullable();
            $table->string('status_after', 20);
            $table->string('confidence', 20);
            $table->text('uncertainty')->nullable();
            $table->timestamp('occurred_at');
            $table->boolean('is_current_revision')->default(true);
            $table->timestamps();

            $table->foreign('operational_event_id')->references('id')->on('telegram_operational_events');
            $table->foreign('observation_id')->references('id')->on('telegram_operational_observations');
            $table->unique(['operational_event_id', 'observation_id', 'role', 'transition'], 'telegram_operational_evidence_unique');
            $table->index(['operational_event_id', 'occurred_at', 'id'], 'telegram_operational_evidence_history_index');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('analytics');
        $schema->dropIfExists('telegram_operational_event_evidence');
        $schema->dropIfExists('telegram_operational_observations');
        $schema->dropIfExists('telegram_operational_events');
    }
};
