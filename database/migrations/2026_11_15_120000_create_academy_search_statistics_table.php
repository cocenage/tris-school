<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_search_statistics', function (Blueprint $table): void {
            $table->string('query_hash', 64)->primary();
            $table->string('query', 120);
            $table->unsignedBigInteger('searches_count')->default(0)->index();
            $table->unsignedBigInteger('no_results_count')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_search_statistics');
    }
};
