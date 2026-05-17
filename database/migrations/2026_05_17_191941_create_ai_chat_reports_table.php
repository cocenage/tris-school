<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_reports', function (Blueprint $table) {
            $table->id();

            $table->date('report_date')->index();
            $table->string('chat_id')->nullable()->index();

            $table->unsignedInteger('messages_count')->default(0);

            $table->longText('prompt')->nullable();
            $table->longText('result')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['report_date', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_reports');
    }
};