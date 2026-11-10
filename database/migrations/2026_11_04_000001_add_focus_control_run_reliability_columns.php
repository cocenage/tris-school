<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_response_drafts', function (Blueprint $table): void {
            $table->string('schema_version', 64)->nullable();
            $table->uuid('run_id')->nullable()->unique();
            $table->unsignedInteger('revision')->default(0);
            $table->string('state', 20)->default('active');
        });

        Schema::table('control_responses', function (Blueprint $table): void {
            $table->string('schema_version', 64)->nullable();
            $table->uuid('submission_token')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('control_responses', function (Blueprint $table): void {
            $table->dropUnique(['submission_token']);
            $table->dropColumn(['schema_version', 'submission_token']);
        });

        Schema::table('control_response_drafts', function (Blueprint $table): void {
            $table->dropUnique(['run_id']);
            $table->dropColumn(['schema_version', 'run_id', 'revision', 'state']);
        });
    }
};
