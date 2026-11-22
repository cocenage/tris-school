<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_v2_media_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Staging rows deliberately outlive a draft deletion so that the
            // pruning command can clean abandoned chunks after submission.
            $table->unsignedBigInteger('control_id');
            $table->unsignedBigInteger('supervisor_id');
            $table->unsignedBigInteger('draft_id');
            $table->uuid('run_id');
            $table->string('schema_version', 64);
            $table->unsignedInteger('room_index');
            $table->unsignedInteger('question_index');
            $table->string('original_name', 180);
            $table->string('declared_mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('chunk_bytes');
            $table->unsignedInteger('total_chunks');
            $table->string('status', 20)->default('pending');
            $table->string('stored_path')->nullable();
            $table->string('actual_mime', 100)->nullable();
            $table->timestamps();

            $table->index(['supervisor_id', 'draft_id', 'status']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_v2_media_uploads');
    }
};
