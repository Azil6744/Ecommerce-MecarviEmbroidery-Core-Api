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
        $tableNames = config('permission.table_names');

        // Enhance permissions table
        if (!empty($tableNames['permissions']) && Schema::hasTable($tableNames['permissions'])) {
            Schema::table($tableNames['permissions'], function (Blueprint $table) {
                if (!Schema::hasColumn($table->getTable(), 'module_id')) {
                    $table->string('module_id')->nullable()->after('guard_name')->index();
                }
                if (!Schema::hasColumn($table->getTable(), 'module_name')) {
                    $table->string('module_name')->nullable()->after('module_id');
                }
                if (!Schema::hasColumn($table->getTable(), 'display_name')) {
                    $table->string('display_name')->nullable()->after('module_name');
                }
                if (!Schema::hasColumn($table->getTable(), 'description')) {
                    $table->text('description')->nullable()->after('display_name');
                }
            });
        }

        // Enhance roles table
        if (!empty($tableNames['roles']) && Schema::hasTable($tableNames['roles'])) {
            Schema::table($tableNames['roles'], function (Blueprint $table) {
                if (!Schema::hasColumn($table->getTable(), 'description')) {
                    $table->text('description')->nullable()->after('guard_name');
                }
                if (!Schema::hasColumn($table->getTable(), 'status')) {
                    $table->boolean('status')->default(true)->after('description');
                }
                if (!Schema::hasColumn($table->getTable(), 'color')) {
                    $table->string('color')->nullable()->after('status');
                }
            });
        }

        // Enhance users table for staff management
        if (Schema::hasTable('users')) {
            // Drop restrictive check constraint if on Postgres
            try {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
            } catch (\Throwable $e) {}

            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'staff_id')) {
                    $table->string('staff_id')->nullable()->unique()->after('email');
                }
                if (!Schema::hasColumn('users', 'department')) {
                    $table->string('department')->nullable()->after('staff_id');
                }
                if (!Schema::hasColumn('users', 'job_title')) {
                    $table->string('job_title')->nullable()->after('department');
                }
                if (!Schema::hasColumn('users', 'status')) {
                    $table->string('status')->default('active')->after('job_title')->index();
                }
                if (!Schema::hasColumn('users', 'two_factor_enabled')) {
                    $table->boolean('two_factor_enabled')->default(false)->after('status');
                }
                if (!Schema::hasColumn('users', 'access_expires_at')) {
                    $table->timestamp('access_expires_at')->nullable()->after('two_factor_enabled');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        if (!empty($tableNames['permissions']) && Schema::hasTable($tableNames['permissions'])) {
            Schema::table($tableNames['permissions'], function (Blueprint $table) {
                $columns = ['module_id', 'module_name', 'display_name', 'description'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table->getTable(), $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (!empty($tableNames['roles']) && Schema::hasTable($tableNames['roles'])) {
            Schema::table($tableNames['roles'], function (Blueprint $table) {
                $columns = ['description', 'status', 'color'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table->getTable(), $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $columns = ['staff_id', 'department', 'job_title', 'status', 'two_factor_enabled', 'access_expires_at'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
