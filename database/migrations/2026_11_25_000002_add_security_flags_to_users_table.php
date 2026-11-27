<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account security / verification flags used by the admin Customer & Business
 * Management directories (verification overview, account alerts, risk panel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'password_update_required_at')) {
                $table->timestamp('password_update_required_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'account_recovery_required_at')) {
                $table->timestamp('account_recovery_required_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'risk_level')) {
                $table->string('risk_level', 20)->nullable();
            }
            if (!Schema::hasColumn('users', 'risk_reason')) {
                $table->string('risk_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['phone_verified_at', 'password_update_required_at', 'account_recovery_required_at', 'risk_level', 'risk_reason'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
