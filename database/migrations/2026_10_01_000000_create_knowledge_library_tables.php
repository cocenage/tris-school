<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type', 40)->default('feature');
            $table->string('status', 40)->default('planned');
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('group_key', 80)->default('knowledge');
            $table->unsignedInteger('sort_order')->default(0);
            $table->nullableMorphs('linked');
            $table->timestamps();
            $table->index(['group_key', 'sort_order']);
        });
        Schema::create('knowledge_entity_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $table->foreignId('target_entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $table->string('relation_type', 40)->default('related_to');
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['source_entity_id', 'target_entity_id', 'relation_type'], 'knowledge_relation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entity_relations');
        Schema::dropIfExists('knowledge_entities');
    }
};
