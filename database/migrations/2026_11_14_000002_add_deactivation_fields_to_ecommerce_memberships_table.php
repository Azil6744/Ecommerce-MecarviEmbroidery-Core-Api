<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_memberships', 'deactivation_scheduled_at')) {
                $table->date('deactivation_scheduled_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'deactivation_reason')) {
                $table->string('deactivation_reason')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'deactivation_details')) {
                $table->text('deactivation_details')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'deactivation_refund_amount')) {
                $table->decimal('deactivation_refund_amount', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            foreach (['deactivation_scheduled_at', 'deactivated_at', 'deactivation_reason', 'deactivation_details', 'deactivation_refund_amount'] as $column) {
                if (Schema::hasColumn('ecommerce_memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
