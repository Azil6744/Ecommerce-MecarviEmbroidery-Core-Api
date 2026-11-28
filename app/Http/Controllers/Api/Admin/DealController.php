<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceCoupon;
use App\Models\Product;
use App\Models\ProductColor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Deals Manager.
 *
 * Deals are stored in ecommerce_coupons with metadata.is_deal = true, so checkout pricing
 * (DealPricingService), usage counting and redemptions keep working unchanged. This controller
 * gives the admin panel a dedicated deal shape (sections 1-11 of the deal form) and gives the
 * user panel / business panel a public list filtered by customer type.
 */
class DealController extends Controller
{
    public const DEAL_TYPES = ['Bundle Deal', 'Flash Deal', 'Buy X Get Y', 'Tiered Discount', 'Seasonal Deal'];
    private const RICH_TEXT_TAGS = '<b><strong><i><em><u><ul><ol><li><p><br><div><span><a>';
    public const BADGES = ['Best Seller', 'Hot Deal', 'Limited Time', 'New', 'Exclusive', 'Top Value', 'None'];

    /** Orders in these states do not count as purchases / revenue. */
    private const NON_REVENUE_STATUSES = ['cancelled', 'canceled', 'refunded', 'failed'];

    // ─── Admin ──────────────────────────────────────────────────────────────

    /** GET /admin/deals — list + the four stat tiles. */
    public function index(Request $request): JsonResponse
    {
        $query = $this->dealsQuery()->with('products:id,name,images,price,sale_price,attributes');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . strtolower($search) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(subtitle, \'\')) LIKE ?', [$like]);
            });
        }

        $deals = $query->orderByDesc('id')->get();
        $stats = $this->orderStats($deals->pluck('code')->all());
        $rows = $deals->map(fn (EcommerceCoupon $deal) => $this->transform($deal, $stats));

        $counts = [
            'total' => $rows->count(),
            'active' => $rows->where('status', 'active')->count(),
            'inactive' => $rows->whereIn('status', ['paused', 'draft', 'scheduled'])->count(),
            'expired' => $rows->where('status', 'expired')->count(),
        ];

        $status = strtolower(trim((string) $request->query('status', 'all')));
        if ($status !== '' && $status !== 'all') {
            $rows = $status === 'inactive'
                ? $rows->whereIn('status', ['paused', 'draft', 'scheduled'])
                : $rows->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'data' => $rows->values(),
            'meta' => [
                'stats' => $counts,
                'deal_types' => self::DEAL_TYPES,
                'badges' => self::BADGES,
            ],
        ]);
    }

    /** GET /admin/deals/{id} */
    public function show(int $id): JsonResponse
    {
        $deal = $this->findDeal($id);

        return response()->json([
            'success' => true,
            'data' => $this->transform($deal, $this->orderStats([$deal->code])),
        ]);
    }

    /** POST /admin/deals */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateDeal($request);

        $deal = DB::transaction(function () use ($data) {
            $deal = EcommerceCoupon::create($this->toCouponPayload($data));
            $this->syncProducts($deal, $data);

            return $deal;
        });

        return response()->json([
            'success' => true,
            'message' => $data['status'] === 'draft' ? 'Deal saved as draft.' : 'Deal published.',
            'data' => $this->transform($deal->fresh()->load('products:id,name,images,price,sale_price,attributes'), []),
        ], 201);
    }

    /** PUT /admin/deals/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $deal = $this->findDeal($id);
        $data = $this->validateDeal($request, $deal);

        DB::transaction(function () use ($deal, $data) {
            $payload = $this->toCouponPayload($data, $deal);
            $deal->update($payload);
            $this->syncProducts($deal, $data);
        });

        return response()->json([
            'success' => true,
            'message' => 'Deal updated.',
            'data' => $this->transform($deal->fresh()->load('products:id,name,images,price,sale_price,attributes'), $this->orderStats([$deal->code])),
        ]);
    }

    /** PATCH /admin/deals/{id}/status — quick pause / activate from the listing. */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $deal = $this->findDeal($id);
        $status = $request->validate(['status' => 'required|in:draft,active,paused'])['status'];

        $metadata = $deal->metadata ?? [];
        $metadata['deal_status'] = $status;
        $deal->update(['is_active' => $status === 'active', 'metadata' => $metadata]);

        return response()->json([
            'success' => true,
            'data' => $this->transform($deal->fresh()->load('products:id,name,images,price,sale_price,attributes'), $this->orderStats([$deal->code])),
        ]);
    }

    /** POST /admin/deals/{id}/duplicate — copy saved as a draft. */
    public function duplicate(int $id): JsonResponse
    {
        $source = $this->findDeal($id);

        $copy = DB::transaction(function () use ($source) {
            $metadata = $source->metadata ?? [];
            $metadata['deal_status'] = 'draft';
            $metadata['slug'] = $this->uniqueSlug(($metadata['slug'] ?? Str::slug($source->title)) . '-copy');

            $copy = $source->replicate(['used_count', 'views_count']);
            $copy->code = $this->uniqueCode($source->code . 'C');
            $copy->title = Str::limit($source->title . ' (Copy)', 100, '');
            $copy->is_active = false;
            $copy->used_count = 0;
            $copy->views_count = 0;
            $copy->metadata = $metadata;
            $copy->save();
            $copy->products()->sync($source->products()->pluck('products.id')->all());

            return $copy;
        });

        return response()->json([
            'success' => true,
            'message' => 'Deal duplicated as a draft.',
            'data' => $this->transform($copy->fresh()->load('products:id,name,images,price,sale_price,attributes'), []),
        ], 201);
    }

    /** DELETE /admin/deals/{id} */
    public function destroy(int $id): JsonResponse
    {
        $deal = $this->findDeal($id);
        $deal->products()->detach();
        $deal->delete();

        return response()->json(['success' => true, 'message' => 'Deal deleted.']);
    }

    /** POST /admin/deals/upload-banner — multipart "file". */
    public function uploadBanner(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:jpeg,png,jpg,webp,gif,svg|max:5120']);

        $file = $request->file('file');
        $name = 'deal_' . now()->format('YmdHis') . '_' . Str::random(8) . '.' . ($file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs('deals', $name, 'public');

        return response()->json([
            'success' => true,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    // ─── Public (user panel / business panel / storefront) ─────────────────

    /**
     * GET /deals?audience=personal|business
     * Live deals only (active, inside their window, not sold out), for the given customer type.
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $audience = $this->audienceFor($request);

        $deals = $this->dealsQuery()
            ->with('products:id,name,images,price,sale_price,attributes')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->orderByDesc('id')
            ->get()
            ->filter(fn (EcommerceCoupon $deal) => $this->visibleTo($deal, $audience));

        // ?product_id=6: only deals that include this product (product details page).
        if ($productId = (int) $request->query('product_id')) {
            $deals = $deals->filter(fn (EcommerceCoupon $deal) => collect($deal->metadata['bundle_items'] ?? [])
                ->contains(fn ($item) => (int) ($item['product_id'] ?? 0) === $productId));
        }

        return response()->json([
            'success' => true,
            'audience' => $audience,
            'data' => $deals->map(fn (EcommerceCoupon $deal) => $this->transformPublic($deal))->values(),
        ]);
    }

    /** GET /deals/{idOrSlug} */
    public function publicShow(Request $request, string $idOrSlug): JsonResponse
    {
        $deal = $this->findPublic($idOrSlug);
        if (! $deal || $deal->status !== 'active' || ! $this->visibleTo($deal, $this->audienceFor($request))) {
            return response()->json(['success' => false, 'message' => 'Deal not found.'], 404);
        }

        $data = $this->transformPublic($deal);

        // ?with=products: full product details for the bundle builder, so it needs one request
        // instead of one per product.
        if ($request->query('with') === 'products') {
            $show = app(\App\Http\Controllers\Api\Ecommerce\ProductController::class);
            $data['products'] = Product::query()
                ->whereIn('id', collect($data['bundle_items'])->pluck('product_id')->filter()->unique()->all())
                ->get()
                ->mapWithKeys(fn (Product $product) => [$product->id => $show->show($product)->getData(true)])
                ->all();
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** POST /deals/{idOrSlug}/view — counts a view of the deal (throttled by route). */
    public function recordView(string $idOrSlug): JsonResponse
    {
        $deal = $this->findPublic($idOrSlug);
        if (! $deal) {
            return response()->json(['success' => false], 404);
        }
        $deal->increment('views_count');

        return response()->json(['success' => true]);
    }

    /**
     * POST /deals/{idOrSlug}/upload-logo — the customer's logo for the bundle builder
     * ("use the same logo on all items"). Stored so every bundle item and the order keep it.
     */
    public function uploadBundleLogo(Request $request, string $idOrSlug): JsonResponse
    {
        $deal = $this->findPublic($idOrSlug);
        if (! $deal || $deal->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Deal not found.'], 404);
        }

        $request->validate(['file' => 'required|file|mimes:jpeg,png,jpg,webp,gif,svg,pdf,ai,eps|max:10240']);
        $file = $request->file('file');
        $name = 'logo_' . now()->format('YmdHis') . '_' . Str::random(10) . '.' . ($file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs('deal-logos/' . (int) $request->user()?->id, $name, 'public');

        return response()->json([
            'success' => true,
            'url' => Storage::disk('public')->url($path),
            'name' => $file->getClientOriginalName(),
        ]);
    }

    // ─── Shaping ────────────────────────────────────────────────────────────

    private function transform(EcommerceCoupon $deal, array $orderStats): array
    {
        $m = $deal->metadata ?? [];
        $tz = $m['time_zone'] ?? config('app.timezone', 'UTC');
        $price = round((float) ($m['bundle_price'] ?? 0), 2);
        $original = round((float) ($m['original_price'] ?? 0), 2);
        $save = $original > $price ? round($original - $price, 2) : 0.0;
        $stat = $orderStats[strtoupper($deal->code)] ?? ['purchases' => 0, 'revenue' => 0.0];
        $usageLimit = $deal->usage_limit;
        $productsById = $deal->relationLoaded('products') ? $deal->products->keyBy('id') : collect();

        $items = collect($m['bundle_items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function ($item) use ($productsById) {
                $product = ! empty($item['product_id']) ? $productsById->get((int) $item['product_id']) : null;

                return [
                    'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                    'name' => $product?->name ?? ($item['name'] ?? ''),
                    'image_url' => $product?->image_url ?? ($item['image_url'] ?? null),
                    'options' => $item['options'] ?? 'Any Option',
                    'qty' => max(1, (int) ($item['qty'] ?? 1)),
                ];
            })->values();

        $starts = $deal->starts_at?->copy()->setTimezone($tz);
        $ends = $deal->expires_at?->copy()->setTimezone($tz);

        return [
            'id' => $deal->id,
            'code' => $deal->code,
            'slug' => $m['slug'] ?? Str::slug($deal->title),
            'title' => $deal->title,
            'deal_type' => $m['deal_type'] ?? 'Bundle Deal',
            'deal_badge' => $m['deal_badge'] ?? 'None',
            'short_description' => $m['short_description'] ?? (string) $deal->subtitle,
            'status' => $this->statusOf($deal),
            'is_active' => (bool) $deal->is_active,

            'starts_at' => $deal->starts_at?->toIso8601String(),
            'expires_at' => $deal->expires_at?->toIso8601String(),
            'start_date' => $starts?->format('Y-m-d'),
            'start_time' => $starts?->format('H:i'),
            'end_date' => $ends?->format('Y-m-d'),
            'end_time' => $ends?->format('H:i'),
            'time_zone' => $tz,
            'duration_days' => ($deal->starts_at && $deal->expires_at)
                ? max(1, (int) ceil($deal->starts_at->diffInMinutes($deal->expires_at) / 1440))
                : null,

            'bundle_items' => $items,
            'bundle_products' => $this->bundleProductsFor($m, $items, $productsById),
            'position_upcharges' => array_values($m['position_upcharges'] ?? []),

            'discount_type' => $m['deal_discount_type'] ?? 'fixed_price',
            'deal_price' => $price,
            'original_value' => $original,
            'savings_amount' => $save,
            // Buy X Get Y: the free share of each group (Buy 2 Get 1 → 33.33%).
            'savings_percentage' => self::isBuyXGetY($m)
                ? round(((int) ($m['get_quantity'] ?? 0)) / max(1, (int) ($m['buy_quantity'] ?? 0) + (int) ($m['get_quantity'] ?? 0)) * 100, 2)
                : ($original > 0 ? round(($save / $original) * 100, 2) : 0),
            'buy_quantity' => isset($m['buy_quantity']) ? (int) $m['buy_quantity'] : null,
            'get_quantity' => isset($m['get_quantity']) ? (int) $m['get_quantity'] : null,
            'free_shipping' => (bool) ($m['free_shipping'] ?? false),
            'free_local_delivery' => (bool) ($m['free_local_delivery'] ?? false),

            'applies_to' => $m['applies_to'] ?? 'all_products',
            'exclude_products' => array_values($m['exclude_products'] ?? []),
            'customer_type' => $m['customer_type'] ?? 'personal',
            'membership_eligibility' => $m['membership_eligibility'] ?? 'all_customers',

            'total_usage_limit' => $usageLimit,
            'used_count' => (int) $deal->used_count,
            'per_customer_limit' => isset($m['per_customer_limit']) ? (int) $m['per_customer_limit'] : null,
            'per_order_limit' => isset($m['per_order_limit']) ? (int) $m['per_order_limit'] : null,
            'stop_when_limit_reached' => $m['stop_when_limit_reached'] ?? 'no_limit',

            'applicable_locations' => $m['applicable_locations'] ?? 'all_locations',
            'shipping_destinations' => $m['shipping_destinations'] ?? 'all_countries',
            'fulfillment_options' => array_values($m['fulfillment_options'] ?? ['shipping']),

            'stackable_with_coupons' => (bool) ($m['stackable_with_coupons'] ?? false),
            'stack_with_memberships' => (bool) ($m['stack_with_memberships'] ?? false),
            'earn_loyalty_rewards' => (bool) ($m['earn_loyalty_rewards'] ?? true),
            'stackable_with_deals' => (bool) ($m['stackable_with_deals'] ?? false),

            'banner_image' => $m['image_url'] ?? null,
            'auto_expire' => (bool) ($m['auto_expire'] ?? true),

            'full_description' => $m['full_description'] ?? '',
            'terms_conditions' => $m['terms_conditions'] ?? '',
            'meta_title' => $m['meta_title'] ?? '',
            'meta_description' => $m['meta_description'] ?? '',

            'unavailable_products' => $this->unavailableProducts($items->pluck('product_id')->all()),
            'stats' => [
                'views' => (int) ($deal->views_count ?? 0),
                'purchases' => (int) $stat['purchases'],
                'usage_percent' => $usageLimit ? min(100, (int) round(((int) $deal->used_count / $usageLimit) * 100)) : 0,
                'revenue' => round((float) $stat['revenue'], 2),
            ],
            'created_at' => $deal->created_at?->toIso8601String(),
            'updated_at' => $deal->updated_at?->toIso8601String(),
        ];
    }

    /** Customer-facing shape: no internal stats. */
    private function transformPublic(EcommerceCoupon $deal): array
    {
        $row = $this->transform($deal, []);
        $remaining = $row['total_usage_limit'] ? max(0, $row['total_usage_limit'] - $row['used_count']) : null;
        unset($row['stats'], $row['is_active'], $row['used_count']);
        $row['remaining'] = $remaining;

        return $row;
    }

    /** draft | paused | scheduled | expired | active */
    private function statusOf(EcommerceCoupon $deal): string
    {
        $m = $deal->metadata ?? [];
        if (! $deal->is_active) {
            return ($m['deal_status'] ?? 'paused') === 'draft' ? 'draft' : 'paused';
        }
        if ($deal->expires_at && $deal->expires_at->isPast()) {
            return 'expired';
        }
        $soldOut = $deal->usage_limit !== null && $deal->used_count >= $deal->usage_limit;
        $onLimit = $m['stop_when_limit_reached'] ?? 'no_limit';
        if ($soldOut && $onLimit === 'expire_deal') {
            return 'expired';
        }
        if ($soldOut && $onLimit === 'pause_deal') {
            return 'paused';
        }
        if ($deal->starts_at && $deal->starts_at->isFuture()) {
            return 'scheduled';
        }

        return 'active';
    }

    /**
     * Purchases and revenue per deal code from placed orders.
     *
     * @return array<string, array{purchases:int, revenue:float}>
     */
    private function orderStats(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper((string) $c), $codes))));
        if (empty($codes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $excluded = implode(',', array_fill(0, count(self::NON_REVENUE_STATUSES), '?'));

        $rows = DB::table('ecommerce_orders')
            ->selectRaw("UPPER(metadata->>'deal_code') as code, COUNT(*) as purchases, COALESCE(SUM(total_amount), 0) as revenue")
            ->whereRaw("UPPER(metadata->>'deal_code') IN ($placeholders)", $codes)
            ->whereRaw("LOWER(COALESCE(status, '')) NOT IN ($excluded)", self::NON_REVENUE_STATUSES)
            ->groupByRaw("UPPER(metadata->>'deal_code')")
            ->get();

        return $rows->mapWithKeys(fn ($row) => [
            $row->code => ['purchases' => (int) $row->purchases, 'revenue' => (float) $row->revenue],
        ])->all();
    }

    // ─── Persistence ────────────────────────────────────────────────────────

    private function validateDeal(Request $request, ?EcommerceCoupon $deal = null): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:100',
            'deal_type' => ['required', 'string', Rule::in(self::DEAL_TYPES)],
            'deal_badge' => ['nullable', 'string', Rule::in(self::BADGES)],
            'short_description' => 'required|string|max:150',
            'slug' => 'nullable|string|max:120',

            'start_date' => 'required|date_format:Y-m-d',
            'start_time' => 'nullable|date_format:H:i',
            'end_date' => 'required|date_format:Y-m-d',
            'end_time' => 'nullable|date_format:H:i',
            'time_zone' => 'required|timezone',

            'bundle_items' => 'array|max:20',
            'bundle_items.*.product_id' => 'nullable|integer|exists:products,id',
            'bundle_items.*.options' => 'nullable|string|max:120',
            'bundle_items.*.qty' => 'nullable|integer|min:1|max:1000',

            // Bundle builder config: per product the styles the customer picks from, and per style the
            // logo positions (price 0 = included, > 0 = upcharge per piece), sizes and colors.
            'bundle_products' => 'nullable|array|max:20',
            'bundle_products.*.product_id' => 'required|integer|exists:products,id',
            'bundle_products.*.qty' => 'required|integer|min:1|max:1000',
            'bundle_products.*.styles' => 'required|array|min:1|max:10',
            'bundle_products.*.styles.*.name' => 'required|string|max:60',
            'bundle_products.*.styles.*.enabled' => 'boolean',
            'bundle_products.*.styles.*.positions' => 'array|max:20',
            'bundle_products.*.styles.*.positions.*.name' => 'required|string|max:60',
            'bundle_products.*.styles.*.positions.*.price' => 'nullable|numeric|min:0|max:10000',
            'bundle_products.*.styles.*.sizes' => 'array|max:20',
            'bundle_products.*.styles.*.sizes.*' => 'string|max:20',
            'bundle_products.*.styles.*.colors' => 'array|max:30',
            'bundle_products.*.styles.*.colors.*.name' => 'required|string|max:40',
            'bundle_products.*.styles.*.colors.*.hex' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position_upcharges' => 'nullable|array|max:30',
            'position_upcharges.*.name' => 'required|string|max:60',
            'position_upcharges.*.price' => 'required|numeric|min:0|max:10000',

            'discount_type' => 'required|in:fixed_price,percentage,fixed_amount,buy_x_get_y',
            'deal_price' => 'nullable|numeric|min:0',
            'original_value' => 'nullable|numeric|min:0',
            'buy_quantity' => 'nullable|integer|min:1|max:1000',
            'get_quantity' => 'nullable|integer|min:1|max:1000',
            'free_shipping' => 'boolean',
            'free_local_delivery' => 'boolean',

            'applies_to' => 'required|in:all_products,specific_products,product_categories,specific_collections',
            'exclude_products' => 'array',
            'exclude_products.*' => 'string|max:120',
            'customer_type' => 'required|in:personal,business,both',
            'membership_eligibility' => 'required|in:all_customers,members_only,non_members',

            'total_usage_limit' => 'nullable|integer|min:1',
            'per_customer_limit' => 'nullable|integer|min:1',
            'per_order_limit' => 'nullable|integer|min:1',
            'stop_when_limit_reached' => 'required|in:no_limit,expire_deal,pause_deal',

            'applicable_locations' => 'required|string|max:100',
            'shipping_destinations' => 'required|string|max:100',
            'fulfillment_options' => 'required|array|min:1',
            'fulfillment_options.*' => 'in:shipping,local_delivery,store_pickup',

            'stackable_with_coupons' => 'boolean',
            'stack_with_memberships' => 'boolean',
            'earn_loyalty_rewards' => 'boolean',
            'stackable_with_deals' => 'boolean',

            'banner_image' => 'nullable|string|max:2000',
            'status' => 'required|in:draft,active,paused',
            'auto_expire' => 'boolean',

            // Rich text (basic HTML); the visible-text limits (2000 / 1000) are checked below.
            'full_description' => 'nullable|string|max:20000',
            'terms_conditions' => 'nullable|string|max:10000',
            'meta_title' => 'nullable|string|max:120',
            'meta_description' => 'nullable|string|max:160',
        ]);

        foreach (['full_description' => 2000, 'terms_conditions' => 1000] as $field => $limit) {
            $data[$field] = strip_tags((string) ($data[$field] ?? ''), self::RICH_TEXT_TAGS);
            if (mb_strlen(trim(html_entity_decode(strip_tags($data[$field])))) > $limit) {
                throw ValidationException::withMessages([$field => ["This text can be up to {$limit} characters."]]);
            }
        }

        $starts = $this->toAppTime($data['start_date'], $data['start_time'] ?? '00:00', $data['time_zone']);
        $ends = $this->toAppTime($data['end_date'], $data['end_time'] ?? '23:59', $data['time_zone']);
        if ($ends->lte($starts)) {
            throw ValidationException::withMessages(['end_date' => ['The end date and time must be after the start.']]);
        }

        // Buy X Get Y (older deals) is priced by count (cheapest Y of every X+Y free), not by a bundle price.
        $isBxGy = $data['discount_type'] === 'buy_x_get_y';

        // Bundle builder config -> the bundle_items checkout pricing counts (product + qty per bundle).
        if (! empty($data['bundle_products'])) {
            $data['bundle_products'] = $this->normalizeBundleProducts($data['bundle_products']);
            $data['bundle_items'] = collect($data['bundle_products'])->map(fn ($p) => [
                'product_id' => $p['product_id'],
                'options' => implode(' / ', collect($p['styles'])->where('enabled', true)->pluck('name')->all()) ?: 'Any Option',
                'qty' => $p['qty'],
            ])->all();
        }
        $data['position_upcharges'] = collect($data['position_upcharges'] ?? [])
            ->map(fn ($u) => ['name' => trim($u['name']), 'price' => round((float) $u['price'], 2)])
            ->filter(fn ($u) => $u['name'] !== '')->unique('name')->values()->all();
        $data['deal_price'] = (float) ($data['deal_price'] ?? 0);
        $data['original_value'] = (float) ($data['original_value'] ?? 0);

        // A live deal must be priceable at checkout.
        $linked = collect($data['bundle_items'] ?? [])->filter(fn ($i) => ! empty($i['product_id']));
        if ($data['status'] !== 'draft') {
            if ($linked->isEmpty()) {
                throw ValidationException::withMessages(['bundle_items' => ['Add at least one product to the bundle before publishing.']]);
            }
            if ($isBxGy) {
                $buy = (int) ($data['buy_quantity'] ?? 0);
                $get = (int) ($data['get_quantity'] ?? 0);
                if ($buy < 1 || $get < 1) {
                    throw ValidationException::withMessages(['buy_quantity' => ['Enter how many customers buy and how many they get free.']]);
                }
                $units = (int) $linked->sum(fn ($i) => max(1, (int) ($i['qty'] ?? 1)));
                if ($units !== $buy + $get) {
                    throw ValidationException::withMessages(['bundle_items' => [
                        "The bundled item quantities must add up to " . ($buy + $get) . " (buy {$buy} + get {$get} free). They add up to {$units}.",
                    ]]);
                }
            } else {
                if ((float) $data['deal_price'] <= 0) {
                    throw ValidationException::withMessages(['deal_price' => ['Enter the deal price before publishing.']]);
                }
                if ((float) $data['original_value'] < (float) $data['deal_price']) {
                    throw ValidationException::withMessages(['original_value' => ['The original value must be at least the deal price.']]);
                }
            }
            foreach ($data['bundle_products'] ?? [] as $p) {
                $styles = collect($p['styles'])->where('enabled', true);
                $name = Product::find($p['product_id'])?->name ?? "Product #{$p['product_id']}";
                if ($styles->isEmpty()) {
                    throw ValidationException::withMessages(['bundle_products' => ["Turn on at least one style for {$name}."]]);
                }
                foreach ($styles as $style) {
                    if (empty($style['positions']) || empty($style['sizes']) || empty($style['colors'])) {
                        throw ValidationException::withMessages(['bundle_products' => [
                            "Add at least one logo position, size and color to {$name} ({$style['name']}).",
                        ]]);
                    }
                }
            }
            $problems = $this->unavailableProducts($linked->pluck('product_id')->all());
            if (! empty($problems)) {
                throw ValidationException::withMessages(['bundle_items' => [
                    'These products cannot be in a live deal: ' . implode('; ', array_map(fn ($p) => "{$p['name']} ({$p['reason']})", $problems))
                    . '. Use active products that are customized and bought online, or save the deal as a draft.',
                ]]);
            }
        }

        $data['starts_at'] = $starts;
        $data['expires_at'] = $ends;
        $data['slug'] = $this->uniqueSlug(Str::slug(($data['slug'] ?? '') ?: $data['title']) ?: 'deal', $deal?->id);

        return $data;
    }

    private function toCouponPayload(array $data, ?EcommerceCoupon $existing = null): array
    {
        $price = round((float) $data['deal_price'], 2);
        $original = round((float) $data['original_value'], 2);
        $save = max(0, round($original - $price, 2));

        $metadata = array_merge($existing?->metadata ?? [], [
            'is_deal' => true,
            'is_bundle' => true,
            'deal_category' => 'bundles',
            'deal_type' => $data['deal_type'],
            'deal_badge' => $data['deal_badge'] ?? 'None',
            'badge' => $data['discount_type'] === 'buy_x_get_y'
                ? 'Buy ' . (int) ($data['buy_quantity'] ?? 0) . ' Get ' . (int) ($data['get_quantity'] ?? 0) . ' Free'
                : ($save > 0 && $original > 0 ? 'Save ' . round(($save / $original) * 100) . '%' : null),
            'short_description' => $data['short_description'],
            'slug' => $data['slug'],
            'time_zone' => $data['time_zone'],

            'bundle_items' => collect($data['bundle_items'] ?? [])
                ->filter(fn ($i) => ! empty($i['product_id']))
                ->map(fn ($i) => [
                    'product_id' => (int) $i['product_id'],
                    'name' => Product::find($i['product_id'])?->name,
                    'options' => $i['options'] ?? 'Any Option',
                    'qty' => max(1, (int) ($i['qty'] ?? 1)),
                ])->values()->all(),

            'builder' => ! empty($data['bundle_products']) ? 'v2' : ($existing?->metadata['builder'] ?? null),
            'bundle_products' => $data['bundle_products'] ?? ($existing?->metadata['bundle_products'] ?? []),
            'position_upcharges' => $data['position_upcharges'] ?? [],

            'deal_discount_type' => $data['discount_type'],
            'buy_quantity' => $data['discount_type'] === 'buy_x_get_y' ? (int) ($data['buy_quantity'] ?? 0) : null,
            'get_quantity' => $data['discount_type'] === 'buy_x_get_y' ? (int) ($data['get_quantity'] ?? 0) : null,
            'bundle_price' => $price,
            'original_price' => $original,
            'savings_amount' => $save,
            'free_shipping' => (bool) ($data['free_shipping'] ?? false),
            'free_local_delivery' => (bool) ($data['free_local_delivery'] ?? false),

            'applies_to' => $data['applies_to'],
            'exclude_products' => array_values($data['exclude_products'] ?? []),
            'customer_type' => $data['customer_type'],
            'membership_eligibility' => $data['membership_eligibility'],

            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'per_order_limit' => $data['per_order_limit'] ?? null,
            'stop_when_limit_reached' => $data['stop_when_limit_reached'],

            'applicable_locations' => $data['applicable_locations'],
            'shipping_destinations' => $data['shipping_destinations'],
            'fulfillment_options' => array_values(array_unique($data['fulfillment_options'])),

            'stackable_with_coupons' => (bool) ($data['stackable_with_coupons'] ?? false),
            'stack_with_memberships' => (bool) ($data['stack_with_memberships'] ?? false),
            'earn_loyalty_rewards' => (bool) ($data['earn_loyalty_rewards'] ?? true),
            'stackable_with_deals' => (bool) ($data['stackable_with_deals'] ?? false),

            'image_url' => $data['banner_image'] ?? null,
            'deal_status' => $data['status'],
            'auto_expire' => (bool) ($data['auto_expire'] ?? true),

            'full_description' => $data['full_description'] ?? '',
            'terms_conditions' => $data['terms_conditions'] ?? '',
            'meta_title' => $data['meta_title'] ?? '',
            'meta_description' => $data['meta_description'] ?? '',
            'apply_method' => 'auto',
        ]);

        // Auto Expire off: the deal keeps running after the end date until an admin stops it.
        $expiresAt = ($data['auto_expire'] ?? true) ? $data['expires_at'] : null;

        $payload = [
            'title' => $data['title'],
            'subtitle' => $data['short_description'],
            'discount_type' => 'fixed',
            'discount_value' => $save,
            'min_order_amount' => 0,
            'usage_limit' => $data['total_usage_limit'] ?? null,
            'starts_at' => $data['starts_at'],
            'expires_at' => $expiresAt,
            'is_active' => $data['status'] === 'active',
            'metadata' => $metadata,
        ];

        if (! $existing) {
            $payload['code'] = $this->uniqueCode(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['slug'])) ?: 'DEAL');
            $payload['used_count'] = 0;
        }

        return $payload;
    }

    private function syncProducts(EcommerceCoupon $deal, array $data): void
    {
        $ids = collect($data['bundle_items'] ?? [])->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $deal->products()->sync($ids);
    }

    private function toAppTime(string $date, string $time, string $tz): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', "$date $time", $tz)->setTimezone(config('app.timezone', 'UTC'));
    }

    // ─── Lookups ────────────────────────────────────────────────────────────

    private function dealsQuery()
    {
        return EcommerceCoupon::query()->where(function ($q) {
            $q->where('metadata->is_deal', true)->orWhere('metadata->is_bundle', true);
        });
    }

    private function findDeal(int $id): EcommerceCoupon
    {
        return $this->dealsQuery()->with('products:id,name,images,price,sale_price,attributes')->findOrFail($id);
    }

    private function findPublic(string $idOrSlug): ?EcommerceCoupon
    {
        $query = $this->dealsQuery()->with('products:id,name,images,price,sale_price,attributes');

        return ctype_digit($idOrSlug)
            ? $query->find((int) $idOrSlug)
            : $query->where('metadata->slug', $idOrSlug)->first();
    }

    /** personal | business. Signed-in business accounts always see business deals. */
    private function audienceFor(Request $request): string
    {
        $user = $request->user();
        if ($user && strtolower((string) $user->role) === 'business') {
            return 'business';
        }

        return strtolower((string) $request->query('audience')) === 'business' ? 'business' : 'personal';
    }

    private function visibleTo(EcommerceCoupon $deal, string $audience): bool
    {
        $m = $deal->metadata ?? [];
        $type = $m['customer_type'] ?? 'both';
        if ($type !== 'both' && $type !== $audience) {
            return false;
        }

        // Sold out deals can no longer be bought, so they are not shown.
        if ($deal->usage_limit !== null && $deal->used_count >= $deal->usage_limit) {
            return false;
        }

        $ids = array_values(array_filter(array_map(fn ($i) => (int) ($i['product_id'] ?? 0), $m['bundle_items'] ?? [])));

        return ! empty($ids) && empty($this->unavailableProducts($ids));
    }

    /**
     * Bundle products a customer cannot customize and buy: missing, inactive, quotation (priced by quote)
     * or digital products.
     *
     * @return array<int, array{product_id:int, name:string, reason:string}>
     */
    private function unavailableProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($ids)) {
            return [];
        }
        $products = Product::query()->whereIn('id', $ids)->get(['id', 'name', 'is_active', 'is_digital', 'product_type'])->keyBy('id');

        $problems = [];
        foreach ($ids as $id) {
            $product = $products->get($id);
            $type = strtolower((string) $product?->product_type);
            $reason = match (true) {
                ! $product => 'deleted',
                ! $product->is_active => 'inactive',
                $type === 'quotation' => 'quotation product',
                (bool) $product->is_digital || $type === 'digital' => 'digital product',
                default => null,
            };
            if ($reason) {
                $problems[] = ['product_id' => $id, 'name' => $product?->name ?? "Product #{$id}", 'reason' => $reason];
            }
        }

        return $problems;
    }

    /** Trims and de-duplicates the builder config sent by the admin form. */
    private function normalizeBundleProducts(array $products): array
    {
        return collect($products)->map(function ($p) {
            $styles = collect($p['styles'] ?? [])->map(fn ($s) => [
                'name' => trim($s['name']),
                'enabled' => (bool) ($s['enabled'] ?? true),
                'positions' => collect($s['positions'] ?? [])
                    ->map(fn ($pos) => ['name' => trim($pos['name']), 'price' => round((float) ($pos['price'] ?? 0), 2)])
                    ->filter(fn ($pos) => $pos['name'] !== '')->unique('name')->values()->all(),
                'sizes' => collect($s['sizes'] ?? [])->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all(),
                'colors' => collect($s['colors'] ?? [])
                    ->map(fn ($c) => ['name' => trim($c['name']), 'hex' => strtoupper($c['hex'])])
                    ->filter(fn ($c) => $c['name'] !== '')->unique('name')->values()->all(),
            ])->filter(fn ($s) => $s['name'] !== '')->unique('name')->values()->all();

            return [
                'product_id' => (int) $p['product_id'],
                'qty' => max(1, (int) $p['qty']),
                'styles' => $styles,
            ];
        })->unique('product_id')->values()->all();
    }

    /**
     * Builder config for the admin form and the customer bundle builder. Deals saved before the
     * builder existed get one "Standard" style built from the product's own colors and placement.
     */
    private function bundleProductsFor(array $m, Collection $items, Collection $productsById): array
    {
        $saved = collect($m['bundle_products'] ?? [])->keyBy('product_id');
        $colorHexMap = self::colorHexMap();

        return $items->filter(fn ($i) => $i['product_id'])->map(function ($item) use ($saved, $productsById, $colorHexMap) {
            $product = $productsById->get($item['product_id'])
                ?? Product::select(['id', 'name', 'images', 'price', 'sale_price', 'attributes'])->find($item['product_id']);
            $config = $saved->get($item['product_id']);
            $options = self::productOptions($product, $colorHexMap);

            $styles = $config['styles'] ?? [self::defaultStyle($product, $options)];
            if (is_array($styles)) {
                $styles = collect($styles)->map(function ($style) use ($colorHexMap) {
                    if (!is_array($style) || empty($style['colors']) || !is_array($style['colors'])) {
                        return $style;
                    }
                    $style['colors'] = collect($style['colors'])->map(function ($c) use ($colorHexMap) {
                        if (!is_array($c) || empty($c['name'])) {
                            return $c;
                        }
                        $nameLower = strtolower(trim((string) $c['name']));
                        $hex = strtoupper((string) ($c['hex'] ?? ''));
                        if (($hex === '' || $hex === '#9CA3AF') && !str_contains($nameLower, 'gray') && !str_contains($nameLower, 'grey')) {
                            $resolved = $colorHexMap[$nameLower] ?? self::COLOR_HEX[$nameLower] ?? null;
                            if ($resolved) {
                                $c['hex'] = $resolved;
                            }
                        }
                        return $c;
                    })->values()->all();
                    return $style;
                })->values()->all();
            }

            return [
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'image_url' => $item['image_url'],
                'unit_price' => $product ? $product->effectiveUnitPrice() : 0.0,
                'qty' => $item['qty'],
                'styles' => $styles,
                'product_options' => $options,
            ];
        })->values()->all();
    }

    public const DEFAULT_SIZES = ['S', 'M', 'L', 'XL', '2XL'];

    private const COLOR_HEX = [
        'black' => '#111111', 'white' => '#FFFFFF', 'navy' => '#1E2A5A', 'red' => '#D7263D', 'royal blue' => '#2348C9',
        'forest green' => '#1F6B3A', 'gray' => '#9CA3AF', 'grey' => '#9CA3AF', 'burgundy' => '#7A1631', 'maroon' => '#7A1631',
        'charcoal' => '#36454F', 'heather gray' => '#B8BCC2', 'light blue' => '#7CC4F0', 'pink' => '#E5157A', 'green' => '#22A447',
        'orange' => '#F97316', 'yellow' => '#FACC15', 'purple' => '#7C3AED', 'brown' => '#7C4A1E', 'khaki' => '#C3B091',
    ];

    /** Map lowercase product color name => uppercase hex code from product_colors table. */
    private static function colorHexMap(): array
    {
        return ProductColor::query()
            ->whereNotNull('hex_code')
            ->get(['name', 'hex_code'])
            ->mapWithKeys(function ($c) {
                $name = strtolower(trim((string) $c->name));
                $hex = strtoupper(trim((string) $c->hex_code));
                return $name !== '' && $hex !== '' ? [$name => $hex] : [];
            })
            ->all();
    }

    /** GET /admin/deals/product-defaults/{productId}: a product's starting style for the bundle editor. */
    public function productDefaults(int $productId): JsonResponse
    {
        $product = Product::with('customizationOptions')->findOrFail($productId);
        $options = self::productOptions($product, self::colorHexMap());

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $product->id,
                'name' => $product->name,
                'image_url' => $product->image_url,
                'unit_price' => $product->effectiveUnitPrice(),
                'styles' => [self::defaultStyle($product, $options)],
                'product_options' => $options,
            ],
        ]);
    }

    /**
     * Extract all saved colors, sizes, positions (placements) and style options for a product
     * from its attributes JSON and product_customization_options rows.
     */
    public static function productOptions(?Product $product, ?array $colorHexMap = null): array
    {
        if (!$product) {
            return ['colors' => [], 'sizes' => [], 'positions' => [], 'styles' => []];
        }

        $colorHexMap ??= self::colorHexMap();
        $attrs = is_array($product->attributes) ? $product->attributes : [];
        $customOpts = $product->relationLoaded('customizationOptions')
            ? $product->customizationOptions
            : $product->customizationOptions()->where('is_active', true)->get();

        // 1. Colors
        $colorsByKey = [];
        $pushColor = function (?string $name, ?string $hex = null) use (&$colorsByKey, $colorHexMap) {
            $name = trim((string) $name);
            if ($name === '') {
                return;
            }
            $key = strtolower($name);
            $resolvedHex = trim((string) $hex);
            if ($resolvedHex === '' || strtoupper($resolvedHex) === '#9CA3AF') {
                $resolvedHex = $colorHexMap[$key] ?? self::COLOR_HEX[$key] ?? ($resolvedHex ?: '#9CA3AF');
            }
            $resolvedHex = strtoupper($resolvedHex);
            if (!isset($colorsByKey[$key])) {
                $colorsByKey[$key] = ['name' => $name, 'hex' => $resolvedHex];
            } elseif ($colorsByKey[$key]['hex'] === '#9CA3AF' && $resolvedHex !== '#9CA3AF') {
                $colorsByKey[$key]['hex'] = $resolvedHex;
            }
        };

        foreach ((array) ($attrs['color_options'] ?? $attrs['availableColors'] ?? []) as $c) {
            if (is_array($c)) {
                $pushColor($c['name'] ?? $c['label'] ?? $c['color'] ?? null, $c['hex'] ?? $c['hex_code'] ?? $c['colorHex'] ?? null);
            } elseif (is_string($c)) {
                $pushColor($c);
            }
        }
        foreach ((array) ($attrs['colorImages'] ?? $attrs['color_images'] ?? []) as $ci) {
            if (is_array($ci)) {
                $pushColor($ci['color'] ?? $ci['name'] ?? null, $ci['hex'] ?? $ci['hex_code'] ?? $ci['colorHex'] ?? null);
            }
        }
        foreach ($customOpts->where('option_type', 'color') as $opt) {
            $meta = is_array($opt->metadata) ? $opt->metadata : [];
            $pushColor($opt->label, $meta['color_hex'] ?? null);
        }

        // 2. Sizes
        $sizesByKey = [];
        $pushSize = function (?string $size) use (&$sizesByKey) {
            $size = trim((string) $size);
            if ($size === '') {
                return;
            }
            $key = strtolower($size);
            if (!isset($sizesByKey[$key])) {
                $sizesByKey[$key] = $size;
            }
        };

        foreach ((array) ($attrs['availableSizes'] ?? []) as $s) {
            $pushSize(is_array($s) ? ($s['name'] ?? $s['label'] ?? null) : (is_string($s) ? $s : null));
        }
        if (is_array($attrs['sizeStep'] ?? null) && ($attrs['sizeStep']['enabled'] ?? true) !== false) {
            foreach ((array) ($attrs['sizeStep']['options'] ?? []) as $opt) {
                if (is_array($opt) && ($opt['checked'] ?? true) !== false) {
                    $pushSize($opt['name'] ?? $opt['label'] ?? null);
                }
            }
        }
        foreach ($customOpts->where('option_type', 'size') as $opt) {
            $pushSize($opt->label);
        }

        // 3. Positions (placements)
        $positionsByKey = [];
        $pushPosition = function (?string $name, $price = 0) use (&$positionsByKey) {
            $name = trim((string) $name);
            if ($name === '') {
                return;
            }
            $key = strtolower($name);
            $numPrice = round(max(0, (float) preg_replace('/[^0-9.\-]/', '', (string) $price)), 2);
            if (!isset($positionsByKey[$key])) {
                $positionsByKey[$key] = ['name' => $name, 'price' => $numPrice];
            }
        };

        $placement = $attrs['placement'] ?? null;
        if (is_string($placement)) {
            $pushPosition($placement, 0);
        } elseif (is_array($placement) && ($placement['enabled'] ?? true) !== false) {
            foreach ((array) ($placement['options'] ?? []) as $opt) {
                if (is_array($opt) && ($opt['checked'] ?? true) !== false) {
                    $pushPosition($opt['name'] ?? $opt['label'] ?? null, $opt['price'] ?? 0);
                }
            }
        }
        foreach ($customOpts->where('option_type', 'placement') as $opt) {
            $pushPosition($opt->label, $opt->price_modifier ?? 0);
        }

        // 4. Styles
        $stylesByKey = [];
        $pushStyle = function (?string $name) use (&$stylesByKey) {
            $name = trim((string) $name);
            if ($name === '') {
                return;
            }
            $key = strtolower($name);
            if (!isset($stylesByKey[$key])) {
                $stylesByKey[$key] = $name;
            }
        };

        foreach ((array) ($attrs['styles'] ?? []) as $st) {
            $pushStyle(is_array($st) ? ($st['name'] ?? $st['label'] ?? null) : (is_string($st) ? $st : null));
        }
        if (is_array($attrs['embroideryType'] ?? null) && ($attrs['embroideryType']['enabled'] ?? true) !== false) {
            foreach ((array) ($attrs['embroideryType']['options'] ?? []) as $opt) {
                if (is_array($opt) && ($opt['checked'] ?? true) !== false) {
                    $pushStyle($opt['name'] ?? $opt['label'] ?? null);
                }
            }
        }
        foreach ((array) ($attrs['product_labels'] ?? []) as $lbl) {
            $pushStyle(is_array($lbl) ? ($lbl['name'] ?? $lbl['label'] ?? null) : (is_string($lbl) ? $lbl : null));
        }
        foreach ($customOpts->whereIn('option_type', ['product_style', 'style']) as $opt) {
            $pushStyle($opt->label);
        }

        return [
            'colors' => array_values($colorsByKey),
            'sizes' => array_values($sizesByKey),
            'positions' => array_values($positionsByKey),
            'styles' => array_values($stylesByKey),
        ];
    }

    public static function defaultStyle(?Product $product, ?array $options = null): array
    {
        $options ??= self::productOptions($product);

        return [
            'name' => 'Standard',
            'enabled' => true,
            'positions' => !empty($options['positions']) ? $options['positions'] : [['name' => 'Front', 'price' => 0.0]],
            'sizes' => !empty($options['sizes']) ? $options['sizes'] : self::DEFAULT_SIZES,
            'colors' => !empty($options['colors']) ? $options['colors'] : [
                ['name' => 'Black', 'hex' => '#111111'],
                ['name' => 'Navy', 'hex' => '#1E2A5A'],
                ['name' => 'White', 'hex' => '#FFFFFF'],
            ],
        ];
    }

    public static function isBuyXGetY(array $metadata): bool
    {
        return ($metadata['deal_discount_type'] ?? null) === 'buy_x_get_y';
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $base = Str::limit($base, 100, '');
        $slug = $base;
        $n = 2;
        while ($this->dealsQuery()
            ->where('metadata->slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    private function uniqueCode(string $base): string
    {
        $base = Str::limit(strtoupper($base), 90, '');
        $code = $base;
        $n = 2;
        while (EcommerceCoupon::where('code', $code)->exists()) {
            $code = $base . $n++;
        }

        return $code;
    }
}
