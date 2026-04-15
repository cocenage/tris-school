<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('days_count')->default(1);

            $table->text('reason');

            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('admin_comment')->nullable();

            $table->timestamp('notified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_requests');
    }
};