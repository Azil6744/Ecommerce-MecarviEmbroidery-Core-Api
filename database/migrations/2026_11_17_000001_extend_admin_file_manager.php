<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Departments and categories share one table, split by `kind`.
        Schema::create('fm_taxonomies', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // department | category
            $table->string('name');
            $table->string('icon_path')->nullable();
            $table->string('icon_key', 40)->nullable();
            $table->string('tone', 20)->default('pink');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'name']);
        });

        Schema::create('fm_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action', 40);       // created | uploaded | updated | deleted | restored | shared
            $table->string('entity_type', 20);  // folder | file | department | category
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_name');
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::table('admin_file_folders', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('admin_file_folders')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('fm_taxonomies')->nullOnDelete();
            $table->string('tone', 20)->nullable();
            $table->boolean('is_protected')->default(false);
            $table->string('password_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('trash_root')->default(false);
            $table->string('trash_batch', 40)->nullable();
            $table->softDeletes();
            $table->index('parent_id');
            $table->index('trash_batch');
        });

        Schema::table('admin_files', function (Blueprint $table) {
            $table->string('original_name')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('fm_taxonomies')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('fm_taxonomies')->nullOnDelete();
            $table->boolean('is_protected')->default(false);
            $table->string('password_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('uploaded_by_name')->nullable();
            $table->boolean('trash_root')->default(false);
            $table->string('trash_batch', 40)->nullable();
            $table->softDeletes();
            $table->index('trash_batch');
        });
    }

    public function down(): void
    {
        Schema::table('admin_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropIndex(['trash_batch']);
            $table->dropSoftDeletes();
            $table->dropColumn(['original_name', 'description', 'is_protected', 'password_hash', 'is_active', 'uploaded_by_name', 'trash_root', 'trash_batch']);
        });
        Schema::table('admin_file_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropIndex(['trash_batch']);
            $table->dropSoftDeletes();
            $table->dropColumn(['tone', 'is_protected', 'password_hash', 'is_active', 'trash_root', 'trash_batch']);
        });
        Schema::dropIfExists('fm_activities');
        Schema::dropIfExists('fm_taxonomies');
    }
};
