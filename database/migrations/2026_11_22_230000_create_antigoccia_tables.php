<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('antigoccia_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apartment_id')->constrained('apartments')->restrictOnDelete();
            $table->date('applied_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['apartment_id', 'applied_on']);
        });

        Schema::create('antigoccia_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apartment_id')->constrained('apartments')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cleaner_name');
            $table->date('cleaning_date');
            $table->json('answers');
            $table->json('photos')->nullable();
            $table->boolean('remaining');
            $table->uuid('submission_uuid')->unique();
            $table->timestamps();
            $table->index(['apartment_id', 'cleaning_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antigoccia_reports');
        Schema::dropIfExists('antigoccia_applications');
    }
};
