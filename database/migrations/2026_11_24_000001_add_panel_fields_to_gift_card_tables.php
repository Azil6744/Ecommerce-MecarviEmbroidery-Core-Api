<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the user/business panel Gift Cards page needs: payment + device
 * capture on orders, fraud flags, lifecycle timestamps, and internal notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_gift_card_orders', function (Blueprint $table) {
            $columns = [
                'payment_method' => fn () => $table->string('payment_method', 50)->nullable(),
                'card_brand' => fn () => $table->string('card_brand', 30)->nullable(),
                'card_last4' => fn () => $table->string('card_last4', 4)->nullable(),
                'transaction_id' => fn () => $table->string('transaction_id', 64)->nullable()->index(),
                'buyer_phone' => fn () => $table->string('buyer_phone', 50)->nullable(),
                'buyer_address' => fn () => $table->string('buyer_address', 500)->nullable(),
                'buyer_business_name' => fn () => $table->string('buyer_business_name')->nullable(),
                'ip_address' => fn () => $table->string('ip_address', 45)->nullable(),
                'user_agent' => fn () => $table->text('user_agent')->nullable(),
                'flagged_reason' => fn () => $table->string('flagged_reason')->nullable(),
                'processed_by' => fn () => $table->unsignedBigInteger('processed_by')->nullable(),
                'processed_by_name' => fn () => $table->string('processed_by_name')->nullable(),
                'paid_at' => fn () => $table->dateTime('paid_at')->nullable(),
                'approved_at' => fn () => $table->dateTime('approved_at')->nullable(),
                'declined_at' => fn () => $table->dateTime('declined_at')->nullable(),
                'declined_reason' => fn () => $table->string('declined_reason')->nullable(),
            ];
            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('ecommerce_gift_card_orders', $name)) {
                    $add();
                }
            }
        });

        Schema::table('ecommerce_gift_cards', function (Blueprint $table) {
            $columns = [
                'flagged_reason' => fn () => $table->string('flagged_reason')->nullable(),
                'voided_at' => fn () => $table->dateTime('voided_at')->nullable(),
                'deactivated_at' => fn () => $table->dateTime('deactivated_at')->nullable(),
            ];
            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('ecommerce_gift_cards', $name)) {
                    $add();
                }
            }
        });

        if (! Schema::hasTable('ecommerce_gift_card_notes')) {
            Schema::create('ecommerce_gift_card_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gift_card_order_id')->nullable()->index();
                $table->unsignedBigInteger('giftcard_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('author_name')->nullable();
                $table->text('note');
                $table->timestamps();
            });
        }

        // Laravel enum() on Postgres is a varchar + CHECK constraint. The panel
        // records Void / Deactivation ledger rows and a Declined order status,
        // so drop those constraints (values stay validated in code).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ecommerce_gift_card_transactions DROP CONSTRAINT IF EXISTS ecommerce_gift_card_transactions_transaction_type_check');
            DB::statement('ALTER TABLE ecommerce_gift_card_orders DROP CONSTRAINT IF EXISTS ecommerce_gift_card_orders_order_status_check');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ecommerce_gift_card_transactions MODIFY transaction_type VARCHAR(50) NOT NULL');
            DB::statement("ALTER TABLE ecommerce_gift_card_orders MODIFY order_status VARCHAR(50) NOT NULL DEFAULT 'Payment Pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_gift_card_notes');

        Schema::table('ecommerce_gift_cards', function (Blueprint $table) {
            foreach (['flagged_reason', 'voided_at', 'deactivated_at'] as $col) {
                if (Schema::hasColumn('ecommerce_gift_cards', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('ecommerce_gift_card_orders', function (Blueprint $table) {
            foreach ([
                'payment_method', 'card_brand', 'card_last4', 'transaction_id', 'buyer_phone', 'buyer_address',
                'buyer_business_name', 'ip_address', 'user_agent', 'flagged_reason', 'processed_by',
                'processed_by_name', 'paid_at', 'approved_at', 'declined_at', 'declined_reason',
            ] as $col) {
                if (Schema::hasColumn('ecommerce_gift_card_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
