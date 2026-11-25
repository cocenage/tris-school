<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_off_requests', function (Blueprint $table): void {
            $table->string('telegram_source_chat_id')->nullable();
            $table->unsignedBigInteger('telegram_source_message_id')->nullable();
        });
        foreach (['day_off_requests', 'vacation_requests', 'inventory_requests'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('telegram_source_thread_id')->nullable();
                // Null identifies historical requests; zero identifies an open compact review.
                $table->unsignedInteger('telegram_review_version')->nullable();
            });
        }
        Schema::table('inventory_request_lines', function (Blueprint $table): void {
            // Approval is not a physical issue. Keep historical issued_qty untouched.
            $table->unsignedInteger('approved_qty')->nullable();
        });
        Schema::create('structured_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->unsignedBigInteger('request_id');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('status');
            $table->json('snapshot');
            $table->text('result_html');
            $table->text('card_html');
            $table->string('source_chat_id');
            $table->unsignedBigInteger('source_message_id');
            $table->string('recipient_chat_id')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->string('card_sync_status')->default('pending');
            $table->unsignedTinyInteger('card_sync_attempts')->default(0);
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'request_id', 'version'], 'structured_request_decision_version');
            $table->index(['delivery_status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structured_request_decisions');
        Schema::table('inventory_request_lines', fn (Blueprint $table) => $table->dropColumn('approved_qty'));
        foreach (['day_off_requests', 'vacation_requests', 'inventory_requests'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['telegram_source_thread_id', 'telegram_review_version']));
        }
        Schema::table('day_off_requests', fn (Blueprint $table) => $table->dropColumn(['telegram_source_chat_id', 'telegram_source_message_id']));
    }
};
