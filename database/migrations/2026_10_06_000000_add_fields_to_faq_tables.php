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
        Schema::table('faq_hero_sections', function (Blueprint $table) {
            if (!Schema::hasColumn('faq_hero_sections', 'badge_title')) {
                $table->string('badge_title')->nullable()->after('id');
            }
            if (!Schema::hasColumn('faq_hero_sections', 'hero_image')) {
                $table->string('hero_image')->nullable()->after('hero_title');
            }
        });

        Schema::table('faq_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('faq_categories', 'description')) {
                $table->text('description')->nullable()->after('category_name');
            }
            if (!Schema::hasColumn('faq_categories', 'icon')) {
                $table->string('icon')->nullable()->after('description');
            }
            if (!Schema::hasColumn('faq_categories', 'image')) {
                $table->string('image')->nullable()->after('icon');
            }
        });

        Schema::table('faq_items', function (Blueprint $table) {
            if (!Schema::hasColumn('faq_items', 'icon')) {
                $table->string('icon')->nullable()->after('answer');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faq_hero_sections', function (Blueprint $table) {
            if (Schema::hasColumn('faq_hero_sections', 'badge_title')) {
                $table->dropColumn('badge_title');
            }
            if (Schema::hasColumn('faq_hero_sections', 'hero_image')) {
                $table->dropColumn('hero_image');
            }
        });

        Schema::table('faq_categories', function (Blueprint $table) {
            if (Schema::hasColumn('faq_categories', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('faq_categories', 'icon')) {
                $table->dropColumn('icon');
            }
            if (Schema::hasColumn('faq_categories', 'image')) {
                $table->dropColumn('image');
            }
        });

        Schema::table('faq_items', function (Blueprint $table) {
            if (Schema::hasColumn('faq_items', 'icon')) {
                $table->dropColumn('icon');
            }
        });
    }
};
