<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_telegram_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->string('original_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('sha256', 64);
            $table->string('status')->default('draft');
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('photo_count')->default(0);
            $table->unsignedInteger('document_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['apartment_id', 'sha256']);
            $table->index(['apartment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_telegram_imports');
    }
};
