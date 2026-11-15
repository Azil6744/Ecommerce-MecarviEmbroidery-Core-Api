<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_memberships', 'cancel_at_period_end')) {
                $table->boolean('cancel_at_period_end')->default(false);
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'cancels_at')) {
                $table->date('cancels_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'cancellation_reason')) {
                $table->string('cancellation_reason')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'cancellation_details')) {
                $table->text('cancellation_details')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'cancellation_refund_amount')) {
                $table->decimal('cancellation_refund_amount', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'cancelled_by')) {
                $table->string('cancelled_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            foreach (['cancel_at_period_end', 'cancels_at', 'cancelled_at', 'cancellation_reason', 'cancellation_details', 'cancellation_refund_amount', 'cancelled_by'] as $column) {
                if (Schema::hasColumn('ecommerce_memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
