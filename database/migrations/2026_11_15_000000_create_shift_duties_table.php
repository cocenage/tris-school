<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_duties', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('district_key');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['date', 'district_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_duties');
    }
};
