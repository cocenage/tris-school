<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_link_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('secret_hash', 64);
            $table->string('comparison_code', 6);
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_link_requests');
    }
};
