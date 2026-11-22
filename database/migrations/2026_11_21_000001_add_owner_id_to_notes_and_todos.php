<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal notes/todos workspaces.
 *
 * owner_id NULL  = the shared admin workspace (/api/admin/notes, /api/admin/todos, existing rows).
 * owner_id = id  = that user's personal workspace (/api/v1/my/notes, /api/v1/my/todos).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['admin_notes', 'admin_note_categories', 'admin_todos'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'owner_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('owner_id')->nullable()->index();
                });
            }
        }

        // Category names were globally unique; they now only need to be unique per workspace.
        if (Schema::hasTable('admin_note_categories')) {
            Schema::table('admin_note_categories', function (Blueprint $t) {
                $t->dropUnique('admin_note_categories_name_unique');
                $t->unique(['owner_id', 'name'], 'admin_note_categories_owner_name_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('admin_note_categories')) {
            Schema::table('admin_note_categories', function (Blueprint $t) {
                $t->dropUnique('admin_note_categories_owner_name_unique');
            });
        }

        foreach (['admin_notes', 'admin_note_categories', 'admin_todos'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'owner_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['owner_id']);
                    $t->dropColumn('owner_id');
                });
            }
        }

        // Personal rows would violate the restored global unique; keep only the admin workspace.
        if (Schema::hasTable('admin_note_categories')) {
            Schema::table('admin_note_categories', function (Blueprint $t) {
                $t->unique('name');
            });
        }
    }
};
