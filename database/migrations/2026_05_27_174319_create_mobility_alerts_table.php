<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('source')->index(); // atm, comune, luceverde, trenord
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('url')->nullable();

            $table->string('type')->nullable(); // strike, transport, roadwork, event
            $table->string('risk')->default('medium'); // low, medium, high
            $table->string('district')->nullable();

            $table->date('starts_at')->nullable()->index();
            $table->date('ends_at')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->string('external_hash')->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_alerts');
    }
};