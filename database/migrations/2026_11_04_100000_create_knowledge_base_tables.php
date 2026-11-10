<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('knowledge_base_categories')) {
            Schema::create('knowledge_base_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('icon')->default('folder');
                $table->string('tone')->default('purple');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('knowledge_base_articles')) {
            Schema::create('knowledge_base_articles', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->foreignId('category_id')
                    ->nullable()
                    ->constrained('knowledge_base_categories')
                    ->nullOnDelete();
                $table->string('tag')->nullable();
                $table->text('summary')->nullable();
                $table->longText('body')->nullable();
                $table->string('status')->default('published')->index(); // published, draft, archived
                $table->string('icon')->default('doc');
                $table->string('tone')->default('purple');
                $table->unsignedInteger('views')->default(0);
                $table->unsignedInteger('helpful_count')->default(0);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_articles');
        Schema::dropIfExists('knowledge_base_categories');
    }
};
