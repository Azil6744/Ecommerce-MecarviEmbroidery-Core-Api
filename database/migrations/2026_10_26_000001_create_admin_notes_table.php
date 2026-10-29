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
        if (!Schema::hasTable('admin_notes')) {
            Schema::create('admin_notes', function (Blueprint $table) {
                $table->id();
                $table->string('note_id')->unique()->index();
                $table->string('title');
                $table->string('category')->default('General')->index();
                $table->string('category_color')->nullable()->default('rose');
                $table->string('category_icon')->nullable()->default('file');
                $table->string('note_date')->nullable();
                $table->boolean('is_pinned')->default(false)->index();
                $table->longText('content')->nullable();
                $table->json('checklist_items')->nullable();
                $table->json('timeline_items')->nullable();
                $table->json('pricing_items')->nullable();
                $table->json('contact_items')->nullable();
                $table->json('bullet_items')->nullable();
                $table->json('gallery_images')->nullable();
                $table->json('attachments')->nullable();
                $table->text('additional_notes')->nullable();
                $table->string('banner_image')->nullable();
                $table->string('thumbnail_image')->nullable();
                $table->string('created_by')->default('Admin');
                $table->integer('order_num')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('admin_note_categories')) {
            Schema::create('admin_note_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('color')->default('rose');
                $table->string('icon')->default('folder');
                $table->integer('order_num')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_notes');
        Schema::dropIfExists('admin_note_categories');
    }
};
