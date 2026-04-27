<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('instruction_category_id')
                ->nullable()
                ->constrained('instruction_categories')
                ->nullOnDelete();

            $table->foreignId('author_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();

            $table->text('short_description')->nullable();

            $table->string('cover_image')->nullable();
            $table->string('icon')->nullable();
            $table->string('emoji')->nullable();
            $table->string('color')->nullable();

            $table->json('blocks')->nullable();

            $table->string('status')->default('draft');
            // draft / published / archived

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_public')->default(true);

            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_featured']);
            $table->index(['instruction_category_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructions');
    }
};