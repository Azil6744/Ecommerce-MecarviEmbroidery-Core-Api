<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A declined / reversed gift card order paid by card is marked
 * `refund_pending` until the payment is refunded in the processor. The
 * original enum (pending, paid, failed, refunded) has no such value, so drop
 * the constraint; allowed values stay validated in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ecommerce_gift_card_orders DROP CONSTRAINT IF EXISTS ecommerce_gift_card_orders_payment_status_check');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ecommerce_gift_card_orders MODIFY payment_status VARCHAR(30) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // Leave the column unconstrained: re-adding the check would fail on refund_pending rows.
    }
};
