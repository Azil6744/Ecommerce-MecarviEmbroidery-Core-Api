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
        if (!Schema::hasTable('knowledge_base_banners')) {
            Schema::create('knowledge_base_banners', function (Blueprint $table) {
                $table->id();
                $table->string('page_key')->unique(); // hub, topics, article
                $table->string('eyebrow')->nullable();
                $table->string('title');
                $table->string('highlight')->nullable();
                $table->text('description')->nullable();
                $table->string('search_placeholder')->nullable();
                $table->string('image_path')->nullable();
                $table->json('features')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('knowledge_base_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_base_categories', 'cover_image_path')) {
                $table->string('cover_image_path')->nullable();
            }
        });

        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            if (!Schema::hasColumn('knowledge_base_articles', 'cover_image_path')) {
                $table->string('cover_image_path')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->dropColumn('cover_image_path');
        });
        Schema::table('knowledge_base_categories', function (Blueprint $table) {
            $table->dropColumn('cover_image_path');
        });
        Schema::dropIfExists('knowledge_base_banners');
    }
};
