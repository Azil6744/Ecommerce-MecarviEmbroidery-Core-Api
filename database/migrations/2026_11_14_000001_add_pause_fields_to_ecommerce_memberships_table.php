<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_memberships', 'pause_starts_at')) {
                $table->date('pause_starts_at')->nullable()->after('billing_cycle');
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'pause_resumes_at')) {
                $table->date('pause_resumes_at')->nullable()->after('pause_starts_at');
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'pause_reason')) {
                $table->text('pause_reason')->nullable()->after('pause_resumes_at');
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'pause_credit_amount')) {
                $table->decimal('pause_credit_amount', 10, 2)->nullable()->after('pause_reason');
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'pause_unused_days')) {
                $table->unsignedInteger('pause_unused_days')->nullable()->after('pause_credit_amount');
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'pause_cycle_days')) {
                $table->unsignedInteger('pause_cycle_days')->nullable()->after('pause_unused_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            foreach (['pause_starts_at', 'pause_resumes_at', 'pause_reason', 'pause_credit_amount', 'pause_unused_days', 'pause_cycle_days'] as $column) {
                if (Schema::hasColumn('ecommerce_memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
