<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentGateway;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('payment_gateways')) {
            return;
        }

        // 1. Check for manual/cod duplicates for Cash on Delivery
        $manualCod = PaymentGateway::where('provider', 'manual')
            ->where(function ($query) {
                $query->where('name', 'like', '%Cash on Delivery%')
                      ->orWhere('display_label', 'like', '%Cash on Delivery%')
                      ->orWhere('display_label', 'like', '%Pay when you receive%');
            })
            ->first();

        $standardCod = PaymentGateway::where('provider', 'cod')->first();

        if ($manualCod && $standardCod) {
            // Both exist: delete the redundant manual row
            $manualCod->delete();
        } elseif ($manualCod && !$standardCod) {
            // Only manual exists: normalize provider to 'cod'
            $manualCod->update([
                'provider' => 'cod',
                'display_label' => $manualCod->display_label ?: 'Cash on Delivery (COD)',
                'description' => $manualCod->description ?: 'Pay with cash upon physical delivery of orders.',
            ]);
        }

        // 2. Remove any other duplicates with the same provider (keeping lowest id)
        $gateways = PaymentGateway::all();
        $seenProviders = [];

        foreach ($gateways as $gw) {
            $prov = strtolower(trim($gw->provider));
            if (isset($seenProviders[$prov])) {
                // Redundant duplicate: remove
                $gw->delete();
            } else {
                $seenProviders[$prov] = $gw->id;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration needed for deduplication
    }
};
