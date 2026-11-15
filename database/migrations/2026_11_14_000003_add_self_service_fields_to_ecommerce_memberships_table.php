<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer self-service membership changes on the local (Core) store:
 * - plan_id links the row to the real subscription plan instead of matching by name/price.
 * - auto_renew persists the "Auto Renewal" toggle.
 * - scheduled_plan_id / scheduled_change_at hold a downgrade that applies at the next billing date.
 * - ecommerce_membership_activities is the local history used for invoices, the admin Activity Log
 *   and lifetime plan revenue (Central keeps its own audit log + transactions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_memberships', 'plan_id')) {
                $table->unsignedBigInteger('plan_id')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'auto_renew')) {
                $table->boolean('auto_renew')->default(true);
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'scheduled_plan_id')) {
                $table->unsignedBigInteger('scheduled_plan_id')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_memberships', 'scheduled_change_at')) {
                $table->date('scheduled_change_at')->nullable();
            }
        });

        if (! Schema::hasTable('ecommerce_membership_activities')) {
            Schema::create('ecommerce_membership_activities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('membership_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('source', 10)->default('local');
                $table->string('action', 50)->index();
                $table->string('from_plan')->nullable();
                $table->string('to_plan')->nullable();
                $table->decimal('amount', 10, 2)->nullable();
                $table->string('payment_method', 50)->nullable();
                $table->string('reference')->nullable();
                $table->json('details')->nullable();
                $table->string('performed_by', 20)->default('customer');
                $table->string('actor_name')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_membership_activities');

        Schema::table('ecommerce_memberships', function (Blueprint $table) {
            foreach (['plan_id', 'auto_renew', 'scheduled_plan_id', 'scheduled_change_at'] as $column) {
                if (Schema::hasColumn('ecommerce_memberships', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
