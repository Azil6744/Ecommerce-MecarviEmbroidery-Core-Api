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
        Schema::create('user_panel_banners', function (Blueprint $table) {
            $table->id();
            $table->string('page_key')->unique()->index();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('footer_text')->nullable();
            $table->string('badge_text')->nullable();
            $table->string('badge_subtext')->nullable();
            $table->string('button_text')->nullable();
            $table->string('button_url')->nullable();
            $table->string('image_path')->nullable();
            $table->string('preset_image_url')->nullable();
            $table->string('theme_color')->nullable()->default('#059669');
            $table->string('accent_color')->nullable()->default('#10b981');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_panel_banners');
    }
};
