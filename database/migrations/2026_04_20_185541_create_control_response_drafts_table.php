<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('control_response_drafts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('control_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cleaner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('apartment_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_assigned')->default(false);
            $table->string('previous_cleaner')->nullable();

            $table->date('cleaning_date')->nullable();
            $table->date('inspection_date')->nullable();

            $table->text('comment')->nullable();

            $table->json('responses')->nullable();
            $table->json('schema_snapshot')->nullable();

            $table->timestamps();

            $table->index(['control_id', 'supervisor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_response_drafts');
    }
};