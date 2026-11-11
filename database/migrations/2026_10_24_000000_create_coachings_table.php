<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coachings')) {
            return;
        }

        Schema::create('coachings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('control_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->foreignId('apartment_id')->nullable()->constrained('apartments')->nullOnDelete();
            $table->date('cleaning_date')->nullable();
            $table->date('inspection_date')->nullable();
            $table->boolean('is_assigned')->default(false);
            $table->string('previous_cleaner')->nullable();
            $table->text('control_response_ids')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('sent');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // A pre-existing legacy coachings table may be adopted by this migration.
        // It is not safe to drop a table whose ownership by this migration is unknown.
    }
};
