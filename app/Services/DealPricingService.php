<?php

namespace App\Services;

use App\Models\EcommerceCoupon;
use Illuminate\Support\Collection;

/**
 * Prices deals (bundle offers), e.g. "10 polos for $200".
 *
 * A deal is an EcommerceCoupon whose metadata has is_deal/is_bundle = true and:
 *   - bundle_price:  what one full bundle costs
 *   - bundle_items:  [{ product_id, qty }] — the products that count towards a bundle and how many
 *                    units of them make one bundle (sum of qty across the items)
 *
 * For each full bundle in the cart, the customer pays bundle_price instead of the unit prices of
 * the units that make up the bundle. Units are taken in cart order; any leftover units are charged
 * at their normal price. The best (largest) deal for the cart is applied.
 */
class DealPricingService
{
    /**
     * @param  array<int, array{product_id:int, unit_price:float, quantity:int}>  $lines
     * @return array{deal: ?EcommerceCoupon, discount: float, bundles: int}
     */
    public function best(array $lines, array $context = []): array
    {
        $best = ['deal' => null, 'discount' => 0.0, 'bundles' => 0];

        foreach ($this->activeDeals() as $deal) {
            $result = $this->evaluate($deal, $lines, $context);
            if ($result['discount'] > $best['discount']) {
                $best = ['deal' => $deal] + $result;
            }
        }

        return $best;
    }

    /**
     * @return array{discount: float, bundles: int}
     */
    public function evaluate(EcommerceCoupon $deal, array $lines, array $context = []): array
    {
        $none = ['discount' => 0.0, 'bundles' => 0];

        if (! $deal->isDeal() || ! $this->isWithinWindow($deal)) {
            return $none;
        }
        if ($deal->usage_limit !== null && (int) $deal->used_count >= (int) $deal->usage_limit) {
            return $none;
        }
        if (! $deal->passesCustomerLimit($context)) {
            return $none;
        }

        $metadata = $deal->metadata ?? [];
        $bundlePrice = round((float) ($metadata['bundle_price'] ?? 0), 2);
        $items = collect($metadata['bundle_items'] ?? [])
            ->filter(fn ($item) => is_array($item) && ! empty($item['product_id']));

        $bundleQty = (int) $items->sum(fn ($item) => max(1, (int) ($item['qty'] ?? 1)));
        $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($bundlePrice <= 0 || $bundleQty < 1 || empty($productIds)) {
            return $none;
        }

        $units = $this->eligibleUnitPrices($lines, $productIds);
        $bundles = intdiv(count($units), $bundleQty);
        if ($bundles < 1) {
            return $none;
        }

        $usedUnits = array_slice($units, 0, $bundles * $bundleQty);
        $normalTotal = round(array_sum($usedUnits), 2);
        $discount = round(max(0, $normalTotal - ($bundles * $bundlePrice)), 2);

        return ['discount' => $discount, 'bundles' => $bundles];
    }

    /** Active deals whose date window is open. */
    public function activeDeals(): Collection
    {
        return EcommerceCoupon::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->get()
            ->filter(fn (EcommerceCoupon $coupon) => $coupon->isDeal());
    }

    private function isWithinWindow(EcommerceCoupon $deal): bool
    {
        return $deal->is_active
            && (! $deal->starts_at || ! $deal->starts_at->isFuture())
            && (! $deal->expires_at || ! $deal->expires_at->isPast());
    }

    /**
     * One entry per unit in the cart that belongs to the deal, carrying that unit's price.
     *
     * @return float[]
     */
    private function eligibleUnitPrices(array $lines, array $productIds): array
    {
        $units = [];
        foreach ($lines as $line) {
            if (! in_array((int) $line['product_id'], $productIds, true)) {
                continue;
            }
            $quantity = max(0, (int) $line['quantity']);
            $unitPrice = round((float) $line['unit_price'], 2);
            for ($i = 0; $i < $quantity; $i++) {
                $units[] = $unitPrice;
            }
        }

        return $units;
    }
}
