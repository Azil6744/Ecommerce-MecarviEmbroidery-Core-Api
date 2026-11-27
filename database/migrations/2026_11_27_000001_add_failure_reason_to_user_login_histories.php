<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_login_histories')) {
            return;
        }
        Schema::table('user_login_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('user_login_histories', 'failure_reason')) {
                $table->string('failure_reason')->nullable();
            }
            if (!Schema::hasColumn('user_login_histories', 'user_agent')) {
                $table->text('user_agent')->nullable();
            }
            if (!Schema::hasColumn('user_login_histories', 'source')) {
                $table->string('source', 50)->nullable(); // core, central, business
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_login_histories')) {
            return;
        }
        Schema::table('user_login_histories', function (Blueprint $table) {
            foreach (['failure_reason', 'user_agent', 'source'] as $col) {
                if (Schema::hasColumn('user_login_histories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
