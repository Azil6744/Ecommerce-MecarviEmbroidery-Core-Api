<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceCoupon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EcommerceCouponController extends Controller
{
    public function index(Request $request)
    {
        $query = EcommerceCoupon::query()
            ->with('products:id,name');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('code', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('subtitle', 'like', "%{$search}%");
            });
        }

        if ($status = trim((string) $request->query('status', ''))) {
            match (strtolower($status)) {
                'active' => $query->where('is_active', true)
                    ->where(function ($inner) {
                        $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                    })
                    ->where(function ($inner) {
                        $inner->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                    }),
                'paused' => $query->where('is_active', false),
                'deactivated' => $query->where('is_active', false),
                'scheduled' => $query->where('is_active', true)->whereNotNull('starts_at')->where('starts_at', '>', now()),
                'inactive' => $query->where(function ($inner) {
                    $inner->where('is_active', false)
                        ->orWhere(function ($nested) {
                            $nested->whereNotNull('expires_at')->where('expires_at', '<', now());
                        })
                        ->orWhere(function ($nested) {
                            $nested->whereNotNull('starts_at')->where('starts_at', '>', now());
                        });
                }),
                'expired' => $query->whereNotNull('expires_at')->where('expires_at', '<', now()),
                default => null,
            };
        }

        if ($type = trim((string) $request->query('type', ''))) {
            $query->where('discount_type', $type);
        }

        $promotionType = strtolower(trim((string) $request->query('promotion_type', '')));
        if ($promotionType === 'deals' || $promotionType === 'deal' || $request->boolean('is_deal')) {
            $query->where(function ($q) {
                $q->where('metadata->is_deal', true)
                    ->orWhere('metadata->is_bundle', true);
            });
        } elseif ($promotionType === 'coupons' || $promotionType === 'coupon') {
            $query->where(function ($q) {
                $q->where(function ($nested) {
                    $nested->whereNull('metadata->is_deal')->orWhere('metadata->is_deal', false);
                })->where(function ($nested) {
                    $nested->whereNull('metadata->is_bundle')->orWhere('metadata->is_bundle', false);
                });
            });
        }

        if ($dealCategory = trim((string) $request->query('deal_category', ''))) {
            if (strtolower($dealCategory) !== 'all' && strtolower($dealCategory) !== 'all deals') {
                $query->where('metadata->deal_category', $dealCategory);
            }
        }

        match (strtolower((string) $request->query('sort', 'newest'))) {
            'oldest' => $query->orderBy('created_at'),
            'code_asc' => $query->orderBy('code'),
            'code_desc' => $query->orderByDesc('code'),
            'usage_desc' => $query->orderByDesc('used_count'),
            'usage_asc' => $query->orderBy('used_count'),
            default => $query->orderByDesc('created_at'),
        };

        $perPage = max(1, min(100, (int) $request->query('per_page', 12)));
        $coupons = $query->paginate($perPage)->appends($request->query());

        $dealsQuery = fn () => EcommerceCoupon::query()->where(function ($q) {
            $q->where('metadata->is_deal', true)->orWhere('metadata->is_bundle', true);
        });

        $couponsOnlyQuery = fn () => EcommerceCoupon::query()->where(function ($q) {
            $q->where(function ($nested) {
                $nested->whereNull('metadata->is_deal')->orWhere('metadata->is_deal', false);
            })->where(function ($nested) {
                $nested->whereNull('metadata->is_bundle')->orWhere('metadata->is_bundle', false);
            });
        });

        $stats = [
            'total' => EcommerceCoupon::count(),
            'total_coupons' => $couponsOnlyQuery()->count(),
            'total_deals' => $dealsQuery()->count(),
            'active' => EcommerceCoupon::query()
                ->where('is_active', true)
                ->where(function ($inner) {
                    $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($inner) {
                    $inner->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->count(),
            'active_coupons' => $couponsOnlyQuery()
                ->where('is_active', true)
                ->where(function ($inner) {
                    $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($inner) {
                    $inner->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->count(),
            'active_deals' => $dealsQuery()
                ->where('is_active', true)
                ->where(function ($inner) {
                    $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($inner) {
                    $inner->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->count(),
            'paused' => EcommerceCoupon::query()->where('is_active', false)->count(),
            'deactivated' => EcommerceCoupon::query()->where('is_active', false)->count(),
            'scheduled' => EcommerceCoupon::query()->where('is_active', true)->whereNotNull('starts_at')->where('starts_at', '>', now())->count(),
            'expired' => EcommerceCoupon::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->count(),
        ];

        $coupons->setCollection($coupons->getCollection()->map(fn (EcommerceCoupon $coupon) => $this->transformCoupon($coupon)));

        return response()->json([
            'success' => true,
            'data' => $coupons,
            'meta' => $stats,
        ]);
    }

    public function show(EcommerceCoupon $coupon)
    {
        return response()->json([
            'success' => true,
            'data' => $this->transformCoupon($coupon->load('products:id,name')),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $coupon = DB::transaction(function () use ($validated) {
            $payload = $this->normalizePayload($validated);
            $this->assertDealValid($payload['metadata']);
            $coupon = EcommerceCoupon::create($payload);
            $this->syncProducts($coupon, $this->productIdsFor($validated, $payload['metadata']));
            return $coupon->load('products:id,name');
        });

        return response()->json([
            'success' => true,
            'data' => $this->transformCoupon($coupon),
            'message' => 'Coupon created successfully',
        ], 201);
    }

    public function update(Request $request, EcommerceCoupon $coupon)
    {
        $validated = $this->validatePayload($request, $coupon);

        DB::transaction(function () use ($coupon, $validated) {
            $payload = $this->normalizePayload($validated, true);
            $metadata = array_key_exists('metadata', $payload) ? $payload['metadata'] : ($coupon->metadata ?? []);
            $this->assertDealValid($metadata);
            $coupon->update($payload);
            if (array_key_exists('product_ids', $validated) || array_key_exists('metadata', $validated)) {
                $this->syncProducts($coupon, $this->productIdsFor($validated, $metadata));
            }
        });

        return response()->json([
            'success' => true,
            'data' => $this->transformCoupon($coupon->fresh()->load('products:id,name')),
            'message' => 'Coupon updated successfully',
        ]);
    }

    public function destroy(EcommerceCoupon $coupon)
    {
        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Coupon deleted successfully',
        ]);
    }

    public function validateCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'subtotal' => 'nullable|numeric|min:0',
            'shipping_amount' => 'nullable|numeric|min:0',
            'product_id' => 'nullable|integer|exists:products,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'customer_email' => 'nullable|email',
        ]);

        $coupon = EcommerceCoupon::query()
            ->with('products:id')
            ->whereRaw('UPPER(code) = ?', [Str::upper(trim($validated['code']))])
            ->first();

        if (! $coupon) {
            return response()->json(['success' => false, 'message' => 'Coupon not found.'], 404);
        }

        // Deals apply automatically when the cart qualifies (see DealPricingService); they are not typed in.
        if ($coupon->isDeal()) {
            return response()->json([
                'success' => false,
                'message' => 'This is a deal. It applies automatically when your cart qualifies.',
            ], 422);
        }

        $subtotal = (float) ($validated['subtotal'] ?? 0);

        $now = now();
        $context = [
            'product_ids' => array_values(array_filter(array_merge(
                isset($validated['product_id']) ? [(int) $validated['product_id']] : [],
                array_map('intval', $validated['product_ids'] ?? [])
            ))),
            'customer_email' => $validated['customer_email'] ?? null,
        ];

        if (! $coupon->is_active || ($coupon->starts_at && $coupon->starts_at > $now) || ($coupon->expires_at && $coupon->expires_at < $now) || ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit)) {
            return response()->json(['success' => false, 'message' => 'Coupon is not active.'], 422);
        }

        if (! $coupon->isUsableFor($subtotal, $context)) {
            return response()->json(['success' => false, 'message' => 'Coupon is not valid for this order.'], 422);
        }

        $discount = $coupon->discountFor($subtotal, $context);
        $shippingDiscount = $coupon->shippingDiscountFor((float) ($validated['shipping_amount'] ?? 0), $subtotal, $context);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'title' => $coupon->title,
                'discount_type' => $coupon->discount_type,
                'discount_value' => (float) $coupon->discount_value,
                'discount_amount' => $discount,
                'shipping_discount_amount' => $shippingDiscount,
                'min_order_amount' => (float) $coupon->min_order_amount,
                'is_active' => (bool) $coupon->is_active,
                'status' => $coupon->status,
            ],
        ]);
    }

    protected function validatePayload(Request $request, ?EcommerceCoupon $coupon = null): array
    {
        $isUpdate = $coupon !== null;

        if ($request->has('code')) {
            $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        }

        return $request->validate([
            'code' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:100',
                Rule::unique('ecommerce_coupons', 'code')->ignore($coupon?->id),
            ],
            'title' => $isUpdate ? 'sometimes|string|max:255' : 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'discount_type' => 'required|string|in:percentage,fixed,free_shipping,buy_x_get_y',
            'discount_value' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'used_count' => 'nullable|integer|min:0',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date' . ($request->filled('starts_at') ? '|after_or_equal:starts_at' : ''),
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
            'metadata.is_deal' => 'nullable|boolean',
            'metadata.is_bundle' => 'nullable|boolean',
            'metadata.deal_category' => 'nullable|string|max:100',
            'metadata.bundle_price' => 'nullable|numeric|min:0',
            'metadata.original_price' => 'nullable|numeric|min:0',
            'metadata.savings_amount' => 'nullable|numeric|min:0',
            'metadata.badge' => 'nullable|string|max:100',
            'metadata.badge_hero' => 'nullable|string|max:100',
            'metadata.badge_sub' => 'nullable|string|max:100',
            'metadata.image_url' => 'nullable|string|max:2000',
            'metadata.bullets' => 'nullable|array',
            'metadata.whats_included' => 'nullable|array',
            'metadata.eligible_products' => 'nullable|string|max:500',
            'metadata.available_sizes' => 'nullable|string|max:500',
            'metadata.decoration_options' => 'nullable|string|max:500',
            'metadata.notes' => 'nullable|string|max:2000',
            'metadata.note' => 'nullable|string|max:255',
            'metadata.side' => 'nullable|string|max:50',
            'metadata.buy_quantity' => 'nullable|integer|min:1',
            'metadata.get_quantity' => 'nullable|integer|min:1',
            'metadata.reward_amount' => 'nullable|numeric|min:0',
            'metadata.discount_value' => 'nullable|numeric|min:0',
            'metadata.max_order_amount' => 'nullable|numeric|min:0',
            'metadata.max_discount_amount' => 'nullable|numeric|min:0',
            'metadata.per_customer_limit' => 'nullable|integer|min:1',
            'metadata.customer_eligibility' => 'nullable|string|max:100',
            'metadata.apply_scope' => 'nullable|string|in:all_products,specific_products,specific_categories,specific_services,specific_customers,membership_plans',
            'metadata.apply_method' => 'nullable|string|in:code,auto',
            'metadata.stacking_rule' => 'nullable|string|max:100',
            'metadata.allow_with_store_credit' => 'nullable|boolean',
            'metadata.allow_with_gift_card' => 'nullable|boolean',
            'metadata.allow_with_loyalty_points' => 'nullable|boolean',
            'metadata.exclude_sale_items' => 'nullable|boolean',
            'metadata.exclude_selected_products' => 'nullable|boolean',
            'metadata.exclude_selected_categories' => 'nullable|boolean',
            'metadata.exclude_gift_cards' => 'nullable|boolean',
            'metadata.exclude_shipping' => 'nullable|boolean',
            'metadata.exclude_taxes' => 'nullable|boolean',
            'metadata.shipping_scope' => 'nullable|string|in:all,specific',
            'metadata.max_shipping_cost' => 'nullable|numeric|min:0',
            'metadata.get_discount_type' => 'nullable|string|in:free,percentage',
            'metadata.get_discount_value' => 'nullable|numeric|min:0',
            'metadata.buy_applies_to' => 'nullable|string|in:same,specific',
            'metadata.bundle_items' => 'nullable|array|max:20',
            'metadata.bundle_items.*.product_id' => 'nullable|integer|exists:products,id',
            'metadata.bundle_items.*.name' => 'nullable|string|max:255',
            'metadata.bundle_items.*.qty' => 'nullable|integer|min:1|max:1000',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);
    }

    protected function normalizePayload(array $validated, bool $isUpdate = false): array
    {
        $payload = Arr::only($validated, [
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
        ]);

        if (array_key_exists('code', $payload)) {
            $payload['code'] = strtoupper(trim((string) $payload['code']));
        } elseif (! $isUpdate) {
            $payload['code'] = Str::upper(Str::random(8));
        }

        if (! array_key_exists('title', $payload) && ! $isUpdate) {
            $payload['title'] = $payload['code'];
        }

        if (! array_key_exists('discount_value', $payload) || $payload['discount_value'] === null) {
            $payload['discount_value'] = 0;
        }

        if (! array_key_exists('min_order_amount', $payload) || $payload['min_order_amount'] === null) {
            $payload['min_order_amount'] = 0;
        }

        if (! array_key_exists('used_count', $payload) || $payload['used_count'] === null) {
            $payload['used_count'] = 0;
        }

        if (! array_key_exists('metadata', $payload) || ! is_array($payload['metadata'])) {
            $payload['metadata'] = [];
        }

        return $payload;
    }

    protected function transformCoupon(EcommerceCoupon $coupon): array
    {
        return $coupon->toManagementArray();
    }

    protected function syncProducts(EcommerceCoupon $coupon, array $productIds): void
    {
        $coupon->products()->sync(array_values(array_unique(array_map('intval', $productIds))));
    }

    /**
     * Products linked to a coupon or deal. For a deal, the products in its bundle are always linked,
     * so the product page and the cart can find the deal.
     */
    protected function productIdsFor(array $validated, array $metadata): array
    {
        $ids = $validated['product_ids'] ?? [];
        if (! empty($metadata['is_deal']) || ! empty($metadata['is_bundle'])) {
            foreach ($metadata['bundle_items'] ?? [] as $item) {
                if (! empty($item['product_id'])) {
                    $ids[] = (int) $item['product_id'];
                }
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * A deal needs a bundle price and at least one product in its bundle.
     */
    protected function assertDealValid(array $metadata): void
    {
        if (empty($metadata['is_deal']) && empty($metadata['is_bundle'])) {
            return;
        }

        $hasProducts = collect($metadata['bundle_items'] ?? [])->contains(fn ($item) => ! empty($item['product_id']));
        if ((float) ($metadata['bundle_price'] ?? 0) <= 0 || ! $hasProducts) {
            throw IlluminateValidationValidationException::withMessages([
                'metadata.bundle_items' => ['A deal needs a bundle price and at least one product in its bundle.'],
            ]);
        }
    }

    public function publicIndex(Request $request)
    {
        $query = \App\Models\EcommerceCoupon::query();

        if ($request->boolean('only_active')) {
            $query->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit');
                });
        }

        $promotionType = strtolower(trim((string) $request->query('promotion_type', '')));
        if ($promotionType === 'deals' || $promotionType === 'deal' || $request->boolean('is_deal')) {
            $query->where(function ($q) {
                $q->where('metadata->is_deal', true)
                    ->orWhere('metadata->is_bundle', true);
            });
        } elseif ($promotionType === 'coupons' || $promotionType === 'coupon') {
            $query->where(function ($q) {
                $q->where(function ($nested) {
                    $nested->whereNull('metadata->is_deal')->orWhere('metadata->is_deal', false);
                })->where(function ($nested) {
                    $nested->whereNull('metadata->is_bundle')->orWhere('metadata->is_bundle', false);
                });
            });
        }

        if ($dealCategory = trim((string) $request->query('deal_category', ''))) {
            if (strtolower($dealCategory) !== 'all' && strtolower($dealCategory) !== 'all deals') {
                $query->where('metadata->deal_category', $dealCategory);
            }
        }

        $coupons = $query->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => $coupons->map(fn (\App\Models\EcommerceCoupon $coupon) => $coupon->toPublicArray())->values(),
        ]);
    }
}
