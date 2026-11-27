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

        // Customer type set on the deal (personal / business / both). Guests count as personal.
        $customerType = $deal->metadata['customer_type'] ?? 'both';
        if ($customerType !== 'both' && $customerType !== ($context['customer_type'] ?? 'personal')) {
            return $none;
        }

        $metadata = $deal->metadata ?? [];

        if (($metadata['deal_discount_type'] ?? null) === 'buy_x_get_y') {
            return $this->evaluateBuyXGetY($deal, $metadata, $lines, $context);
        }

        $bundlePrice = round((float) ($metadata['bundle_price'] ?? 0), 2);
        $items = collect($metadata['bundle_items'] ?? [])
            ->filter(fn ($item) => is_array($item) && ! empty($item['product_id']));

        // Units of each product one bundle needs, e.g. [polo => 10, cap => 5].
        $required = [];
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $required[$pid] = ($required[$pid] ?? 0) + max(1, (int) ($item['qty'] ?? 1));
        }

        if ($bundlePrice <= 0 || empty($required)) {
            return $none;
        }

        // Deals are for signed-in customers only.
        if (empty($context['user_id'])) {
            return $none;
        }

        // Unit prices per product. Cart prices already include customization (embroidery, setup),
        // so the deal price covers the customized items. Lines added through this deal's bundle
        // builder are used first.
        $unitsByProduct = [];
        foreach ($required as $pid => $qty) {
            $unitsByProduct[$pid] = $this->eligibleUnitPrices($lines, [$pid], $deal->code, $metadata);
        }

        // Every product must have its full quantity for a bundle to count.
        $bundles = PHP_INT_MAX;
        foreach ($required as $pid => $qty) {
            $bundles = min($bundles, intdiv(count($unitsByProduct[$pid]), $qty));
        }
        // Per Order Limit: how many times the bundle can apply in one order.
        $perOrder = (int) ($metadata['per_order_limit'] ?? 0);
        if ($perOrder > 0) {
            $bundles = min($bundles, $perOrder);
        }
        if ($bundles < 1 || $bundles === PHP_INT_MAX) {
            return $none;
        }

        $normalTotal = 0.0;
        foreach ($required as $pid => $qty) {
            $normalTotal += array_sum(array_slice($unitsByProduct[$pid], 0, $bundles * $qty));
        }
        $discount = round(max(0, round($normalTotal, 2) - ($bundles * $bundlePrice)), 2);

        return ['discount' => $discount, 'bundles' => $bundles];
    }

    /**
     * Buy X Get Y: every full group of X+Y units of the deal's products gets its Y cheapest units free.
     * E.g. Buy 2 Get 1 with 3 shirts → pay for 2; Buy 6 Get 2 with 8 items → pay for 6, with 5 or 7 → no deal
     * for the incomplete group. Unit prices include customization.
     *
     * @return array{discount: float, bundles: int}
     */
    private function evaluateBuyXGetY(EcommerceCoupon $deal, array $metadata, array $lines, array $context): array
    {
        $none = ['discount' => 0.0, 'bundles' => 0];
        $buy = (int) ($metadata['buy_quantity'] ?? 0);
        $get = (int) ($metadata['get_quantity'] ?? 0);
        $productIds = collect($metadata['bundle_items'] ?? [])
            ->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($buy < 1 || $get < 1 || empty($productIds) || empty($context['user_id'])) {
            return $none;
        }

        $units = $this->eligibleUnitPrices($lines, $productIds, $deal->code);
        $groups = intdiv(count($units), $buy + $get);
        $perOrder = (int) ($metadata['per_order_limit'] ?? 0);
        if ($perOrder > 0) {
            $groups = min($groups, $perOrder);
        }
        if ($groups < 1) {
            return $none;
        }

        // The cheapest units are the free ones.
        sort($units, SORT_NUMERIC);
        $discount = round(array_sum(array_slice($units, 0, $groups * $get)), 2);

        return ['discount' => $discount, 'bundles' => $groups];
    }

    /**
     * Per-piece upcharge of the logo positions a customer picked in the bundle builder: the style's own
     * positions first (price 0 = included), then the deal's additional position upcharges.
     */
    public static function upchargeFor(array $metadata, int $productId, ?string $style, $positions): float
    {
        if (! is_array($positions) || empty($positions)) {
            return 0.0;
        }
        $prices = [];
        foreach ($metadata['position_upcharges'] ?? [] as $u) {
            $prices[strtolower(trim((string) ($u['name'] ?? '')))] = (float) ($u['price'] ?? 0);
        }
        foreach ($metadata['bundle_products'] ?? [] as $product) {
            if ((int) ($product['product_id'] ?? 0) !== $productId) {
                continue;
            }
            foreach ($product['styles'] ?? [] as $s) {
                if ($style !== null && strcasecmp((string) ($s['name'] ?? ''), $style) !== 0) {
                    continue;
                }
                foreach ($s['positions'] ?? [] as $pos) {
                    $prices[strtolower(trim((string) ($pos['name'] ?? '')))] = (float) ($pos['price'] ?? 0);
                }
            }
        }

        $total = 0.0;
        foreach (array_unique(array_map(fn ($n) => strtolower(trim((string) $n)), $positions)) as $name) {
            $total += $prices[$name] ?? 0.0;
        }

        return round($total, 2);
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
     * Lines from the deal's bundle builder leave out their logo position upcharges: those are
     * charged on top of the deal price, so the deal never discounts them.
     *
     * @return float[]
     */
    private function eligibleUnitPrices(array $lines, array $productIds, ?string $dealCode = null, array $metadata = []): array
    {
        $tagged = [];
        $other = [];
        foreach ($lines as $line) {
            if (! in_array((int) $line['product_id'], $productIds, true)) {
                continue;
            }
            $quantity = max(0, (int) $line['quantity']);
            $unitPrice = round((float) $line['unit_price'], 2);
            $isTagged = $dealCode !== null && strtoupper((string) ($line['deal_code'] ?? '')) === strtoupper($dealCode);
            if ($isTagged) {
                $unitPrice = max(0.0, round($unitPrice - self::upchargeFor($metadata, (int) $line['product_id'], $line['deal_style'] ?? null, $line['deal_positions'] ?? []), 2));
            }
            for ($i = 0; $i < $quantity; $i++) {
                if ($isTagged) {
                    $tagged[] = $unitPrice;
                } else {
                    $other[] = $unitPrice;
                }
            }
        }

        return array_merge($tagged, $other);
    }
}
