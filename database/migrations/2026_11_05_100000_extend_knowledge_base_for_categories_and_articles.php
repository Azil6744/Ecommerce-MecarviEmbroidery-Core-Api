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
        Schema::table('knowledge_base_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_base_categories', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('knowledge_base_categories', 'meta_title')) {
                $table->string('meta_title')->nullable();
            }
            if (!Schema::hasColumn('knowledge_base_categories', 'meta_description')) {
                $table->string('meta_description', 300)->nullable();
            }
            if (!Schema::hasColumn('knowledge_base_categories', 'status')) {
                $table->string('status')->default('active'); // active, inactive
            }
            if (!Schema::hasColumn('knowledge_base_categories', 'icon_path')) {
                $table->string('icon_path')->nullable();
            }
        });

        if (!Schema::hasTable('knowledge_base_subcategories')) {
            Schema::create('knowledge_base_subcategories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')
                    ->constrained('knowledge_base_categories')
                    ->cascadeOnDelete();
                $table->string('name');
                $table->string('slug')->nullable()->unique();
                $table->string('meta_title')->nullable();
                $table->string('meta_description', 300)->nullable();
                $table->unsignedInteger('display_order')->default(0);
                $table->string('status')->default('active'); // active, inactive
                $table->string('icon_path')->nullable();
                $table->timestamps();

                $table->unique(['category_id', 'name']);
            });
        }

        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_base_articles', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('title');
            }
            if (!Schema::hasColumn('knowledge_base_articles', 'subcategory_id')) {
                $table->foreignId('subcategory_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('knowledge_base_subcategories')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('knowledge_base_articles', 'meta_title')) {
                $table->string('meta_title', 255)->nullable();
            }
            if (!Schema::hasColumn('knowledge_base_articles', 'meta_description')) {
                $table->string('meta_description', 300)->nullable();
            }
            if (!Schema::hasColumn('knowledge_base_articles', 'content_blocks')) {
                $table->json('content_blocks')->nullable();
            }
            if (!Schema::hasColumn('knowledge_base_articles', 'attachments')) {
                $table->json('attachments')->nullable();
            }
        });

        if (!Schema::hasTable('knowledge_base_article_relations')) {
            Schema::create('knowledge_base_article_relations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('article_id')
                    ->constrained('knowledge_base_articles')
                    ->cascadeOnDelete();
                $table->foreignId('related_article_id')
                    ->constrained('knowledge_base_articles')
                    ->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['article_id', 'related_article_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_article_relations');

        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->dropForeign(['subcategory_id']);
            $table->dropColumn(['slug', 'subcategory_id', 'meta_title', 'meta_description', 'content_blocks', 'attachments']);
        });

        Schema::dropIfExists('knowledge_base_subcategories');

        Schema::table('knowledge_base_categories', function (Blueprint $table) {
            $table->dropColumn(['slug', 'meta_title', 'meta_description', 'status', 'icon_path']);
        });
    }
};
