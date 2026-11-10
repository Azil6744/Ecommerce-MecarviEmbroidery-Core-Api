<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceCoupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'subtitle',
        'discount_type',
        'discount_value',
        'min_order_amount',
        'usage_limit',
        'used_count',
        'starts_at',
        'expires_at',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'used_count' => 'integer',
        'usage_limit' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    protected $appends = [
        'status',
        'is_expired',
        'is_scheduled',
        'usage_remaining',
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'ecommerce_coupon_product', 'coupon_id', 'product_id');
    }

    public function isUsableFor(float $subtotal, array $context = []): bool
    {
        if (! $this->is_active) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) return false;
        if ($subtotal < (float) $this->min_order_amount) return false;

        $metadata = $this->metadata ?? [];
        $maxOrderAmount = $metadata['max_order_amount'] ?? null;
        if ($maxOrderAmount !== null && $maxOrderAmount !== '' && $subtotal > (float) $maxOrderAmount) return false;

        if (! $this->appliesToProducts($context['product_ids'] ?? [])) return false;
        if (! $this->passesCustomerLimit($context)) return false;

        return true;
    }

    public function discountFor(float $subtotal, array $context = []): float
    {
        if (! $this->isUsableFor($subtotal, $context)) return 0;

        if ($this->discount_type === 'percentage') {
            return $this->capDiscount(round($subtotal * ((float) $this->discount_value / 100), 2));
        }

        if ($this->discount_type === 'free_shipping') {
            return 0;
        }

        if ($this->discount_type === 'buy_x_get_y') {
            return $this->capDiscount(round($this->buyXGetYDiscount($context['lines'] ?? []), 2));
        }

        return min($subtotal, $this->capDiscount(round((float) $this->discount_value, 2)));
    }

    /**
     * Buy X get Y: for every full group of (buy + get) eligible units in the cart, the cheapest
     * `get` units are discounted — free, or by a percentage when get_discount_type is percentage.
     *
     * @param  array<int, array{product_id:int, unit_price:float, quantity:int}>  $lines
     */
    public function buyXGetYDiscount(array $lines): float
    {
        $metadata = $this->metadata ?? [];
        $buy = (int) ($metadata['buy_quantity'] ?? 0);
        $get = (int) ($metadata['get_quantity'] ?? 0);
        if ($buy < 1 || $get < 1) {
            return 0.0;
        }

        $couponProductIds = $this->relationLoaded('products')
            ? $this->products->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all();
        $restricted = ($metadata['apply_scope'] ?? 'all_products') === 'specific_products';

        // Eligible units, one entry per unit, grouped by product
        $unitsByProduct = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            if ($restricted && ! in_array($productId, $couponProductIds, true)) {
                continue;
            }
            for ($i = 0; $i < max(0, (int) $line['quantity']); $i++) {
                $unitsByProduct[$productId][] = round((float) $line['unit_price'], 2);
            }
        }

        // "same" = the buy and get units come from the same product; "specific"/other = pooled across products
        $pooled = ($metadata['buy_applies_to'] ?? 'same') !== 'same';
        $groups = $pooled ? [array_merge(...array_values($unitsByProduct ?: [[]]))] : array_values($unitsByProduct);

        $discount = 0.0;
        $percentage = ($metadata['get_discount_type'] ?? 'free') === 'percentage';
        $percentValue = (float) ($metadata['get_discount_value'] ?? 0);

        foreach ($groups as $units) {
            $cycle = $buy + $get;
            $fullGroups = intdiv(count($units), $cycle);
            if ($fullGroups < 1) {
                continue;
            }
            $freeCount = $fullGroups * $get;
            sort($units);
            foreach (array_slice($units, 0, $freeCount) as $price) {
                $discount += $percentage ? $price * ($percentValue / 100) : $price;
            }
        }

        return round($discount, 2);
    }

    public function shippingDiscountFor(float $shippingAmount, float $subtotal, array $context = []): float
    {
        if ($this->discount_type !== 'free_shipping' || ! $this->isUsableFor($subtotal, $context)) {
            return 0;
        }

        $maxShippingCost = $this->metadata['max_shipping_cost'] ?? null;
        $discount = round(max(0, $shippingAmount), 2);

        if ($maxShippingCost !== null && $maxShippingCost !== '') {
            $discount = min($discount, round((float) $maxShippingCost, 2));
        }

        return $discount;
    }

    /** Deals (bundle offers) are stored as promotions with is_deal/is_bundle. They are priced by DealPricingService. */
    public function isDeal(): bool
    {
        $metadata = $this->metadata ?? [];

        return ! empty($metadata['is_deal']) || ! empty($metadata['is_bundle']);
    }

    public function appliesToProducts(array $productIds = []): bool
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        $metadata = $this->metadata ?? [];

        $couponProductIds = $this->relationLoaded('products')
            ? $this->products->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all();

        // Exclusion: the coupon applies to every product EXCEPT the selected ones.
        // The cart is eligible unless it contains one of the excluded products.
        if (! empty($metadata['exclude_selected_products'])) {
            return empty(array_intersect($couponProductIds, $productIds));
        }

        if (($metadata['apply_scope'] ?? 'all_products') === 'all_products') {
            return true;
        }

        // Scopes that are not implemented yet must not fall through to "all products".
        // Until they are, the coupon does not apply.
        if (! in_array($metadata['apply_scope'] ?? null, ['specific_products'], true)) {
            return false;
        }

        if (($metadata['apply_scope'] ?? null) === 'specific_products' && empty($couponProductIds)) {
            return false;
        }

        if (! empty($couponProductIds) && empty($productIds)) {
            return false;
        }

        if (! empty($couponProductIds) && empty(array_intersect($couponProductIds, $productIds))) {
            return false;
        }

        return true;
    }

    public function passesCustomerLimit(array $context = []): bool
    {
        $metadata = $this->metadata ?? [];
        $limit = (int) ($metadata['per_customer_limit'] ?? 0);
        if ($limit <= 0) {
            return true;
        }

        $userId = $context['user_id'] ?? null;
        $email = strtolower(trim((string) ($context['customer_email'] ?? '')));
        if (! $userId && $email === '') {
            return true;
        }

        $used = EcommerceOrder::query()
            ->where(function ($query) {
                $query->where('metadata->coupon_code', $this->code)
                    ->orWhere('metadata->coupon->code', $this->code);
            })
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->when(! $userId && $email !== '', fn ($query) => $query->whereRaw('LOWER(customer_email) = ?', [$email]))
            ->count();

        return $used < $limit;
    }

    private function capDiscount(float $discount): float
    {
        $maxDiscount = $this->metadata['max_discount_amount'] ?? null;
        if ($maxDiscount !== null && $maxDiscount !== '' && (float) $maxDiscount > 0) {
            return min($discount, round((float) $maxDiscount, 2));
        }

        return $discount;
    }

    public function getStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'paused';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function getIsExpiredAttribute(): bool
    {
        return (bool) ($this->expires_at && $this->expires_at->isPast());
    }

    public function getIsScheduledAttribute(): bool
    {
        return (bool) ($this->starts_at && $this->starts_at->isFuture());
    }

    public function getUsageRemainingAttribute(): ?int
    {
        if ($this->usage_limit === null) {
            return null;
        }

        return max(0, (int) $this->usage_limit - (int) $this->used_count);
    }

    public function toManagementArray(): array
    {
        $metadata = $this->metadata ?? [];
        $isDeal = !empty($metadata['is_deal']) || !empty($metadata['is_bundle']);

        return array_merge($this->toPublicArray(), [
            'is_deal' => $isDeal,
            'deal_category' => $metadata['deal_category'] ?? ($isDeal ? 'bundles' : null),
            'used_count' => (int) $this->used_count,
            'usage_remaining' => $this->usage_remaining,
            'products' => $this->relationLoaded('products')
                ? $this->products->map(fn ($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                ])->values()->all()
                : [],
        ]);
    }

    public function toPublicArray(): array
    {
        $metadata = $this->metadata ?? [];
        $isDeal = !empty($metadata['is_deal']) || !empty($metadata['is_bundle']);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'min_order_amount' => (float) $this->min_order_amount,
            'usage_limit' => $this->usage_limit,
            'used_count' => (int) $this->used_count,
            'usage_remaining' => $this->usage_remaining,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
            'status' => $this->status,
            'is_expired' => $this->is_expired,
            'is_scheduled' => $this->is_scheduled,
            'is_deal' => $isDeal,
            'deal_category' => $metadata['deal_category'] ?? ($isDeal ? 'bundles' : null),
            'bundle_price' => !empty($metadata['bundle_price']) ? (float) $metadata['bundle_price'] : null,
            'original_price' => !empty($metadata['original_price']) ? (float) $metadata['original_price'] : null,
            'savings_amount' => !empty($metadata['savings_amount']) ? (float) $metadata['savings_amount'] : null,
            'image_url' => $metadata['image_url'] ?? null,
            'max_discount_amount' => !empty($metadata['max_discount_amount']) ? (float) $metadata['max_discount_amount'] : null,
            'buy_quantity' => !empty($metadata['buy_quantity']) ? (int) $metadata['buy_quantity'] : null,
            'get_quantity' => !empty($metadata['get_quantity']) ? (int) $metadata['get_quantity'] : null,
            'apply_scope' => $metadata['apply_scope'] ?? 'all_products',
            'per_customer_limit' => !empty($metadata['per_customer_limit']) ? (int) $metadata['per_customer_limit'] : null,
            'metadata' => $metadata,
            'display' => [
                'note' => $metadata['note'] ?? ($this->expires_at ? 'Valid till: ' . $this->expires_at->toFormattedDateString() : 'Valid offer'),
                'side' => $metadata['side'] ?? null,
                'badge' => $this->displayBadge(),
            ],
        ];
    }

    public function displayBadge(): string
    {
        $metadata = $this->metadata ?? [];
        if (!empty($metadata['badge'])) {
            return (string) $metadata['badge'];
        }

        if ($this->discount_type === 'percentage') {
            return rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.') . '% OFF';
        }

        if ($this->discount_type === 'fixed') {
            return '$' . rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.') . ' OFF';
        }

        if ($this->discount_type === 'free_shipping') {
            return 'FREE SHIPPING';
        }

        if ($this->discount_type === 'buy_x_get_y') {
            $buy = (int) ($this->metadata['buy_quantity'] ?? 0);
            $get = (int) ($this->metadata['get_quantity'] ?? 0);
            if ($buy > 0 && $get > 0) {
                return "BUY {$buy} GET {$get} FREE";
            }
            return 'BUY X GET Y';
        }

        return 'SPECIAL PROMO';
    }
}
