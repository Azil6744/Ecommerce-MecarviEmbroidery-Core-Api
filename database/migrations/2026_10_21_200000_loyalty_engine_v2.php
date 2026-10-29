<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_loyalty_transactions', function (Blueprint $table) {
            // Points of an earn "lot" that are still spendable (FIFO redemption / expiry).
            if (!Schema::hasColumn('ecommerce_loyalty_transactions', 'remaining_points')) {
                $table->integer('remaining_points')->default(0)->after('points');
            }
            if (!Schema::hasColumn('ecommerce_loyalty_transactions', 'expiry_reminder_sent_at')) {
                $table->timestamp('expiry_reminder_sent_at')->nullable();
            }
            $table->index(['user_id', 'status']);
            $table->index(['order_id']);
            $table->index(['expiration_date']);
        });

        Schema::table('customer_points_balances', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_points_balances', 'needs_review')) {
                $table->boolean('needs_review')->default(false);
                $table->string('review_reason')->nullable();
            }
        });

        if (!Schema::hasTable('loyalty_audit_logs')) {
            Schema::create('loyalty_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 80);
                $table->text('reason')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'action']);
            });
        }

        // Normalise legacy statuses to the spec vocabulary:
        // pending | available | redeemed | reversed | expired | manual_added | manual_removed
        DB::table('ecommerce_loyalty_transactions')->where('status', 'completed')->where('transaction_type', 'redeemed')->update(['status' => 'redeemed']);
        DB::table('ecommerce_loyalty_transactions')->where('status', 'completed')->where('transaction_type', 'reversed')->update(['status' => 'reversed']);
        DB::table('ecommerce_loyalty_transactions')->where('status', 'completed')->where('transaction_type', 'expired')->update(['status' => 'expired']);
        DB::table('ecommerce_loyalty_transactions')->where('status', 'completed')->where('transaction_type', 'manual_removed')->update(['status' => 'manual_removed']);
        DB::table('ecommerce_loyalty_transactions')->where('status', 'completed')->where('points', '<', 0)->update(['status' => 'manual_removed']);
        DB::table('ecommerce_loyalty_transactions')->where('status', 'completed')->where('transaction_type', 'manual_added')->update(['status' => 'manual_added']);
        DB::table('ecommerce_loyalty_transactions')->where('status', 'completed')->update(['status' => 'available']);

        // Spendable remainder for existing positive lots
        DB::table('ecommerce_loyalty_transactions')
            ->whereIn('status', ['available', 'manual_added'])
            ->where('points', '>', 0)
            ->where('remaining_points', 0)
            ->update(['remaining_points' => DB::raw('points')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_audit_logs');
        Schema::table('customer_points_balances', function (Blueprint $table) {
            $table->dropColumn(['needs_review', 'review_reason']);
        });
        Schema::table('ecommerce_loyalty_transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['order_id']);
            $table->dropIndex(['expiration_date']);
            $table->dropColumn(['remaining_points', 'expiry_reminder_sent_at']);
        });
    }
};
