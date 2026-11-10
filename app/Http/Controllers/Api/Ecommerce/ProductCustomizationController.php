<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\EcommerceCoupon;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderItem;
use App\Models\EcommerceQuotation;
use App\Models\Product;
use App\Models\ProductCustomizationDraft;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductCustomizationController extends Controller
{
    public function pricePreview(Request $request, Product $product)
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
            'selected_options' => ['nullable', 'array'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->calculatePrice($product, $validated, $request->user()?->id),
        ]);
    }

    public function storeDraft(Request $request, Product $product)
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
            'selected_options' => ['nullable', 'array'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);

        $pricing = $this->calculatePrice($product, $validated, $request->user()?->id);
        $draft = ProductCustomizationDraft::create([
            'product_id' => $product->id,
            'user_id' => optional($request->user())->id,
            'session_id' => $this->sessionId($request),
            'selected_options' => $validated['selected_options'] ?? [],
            'quantity' => $pricing['quantity'],
            'unit_price' => $pricing['unit_price'],
            'setup_fee' => $pricing['setup_fee'],
            'discount_amount' => $pricing['discount_amount'],
            'total_price' => $pricing['total_price'],
            'coupon_code' => $pricing['coupon_code'],
            'metadata' => $validated['metadata'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'data' => $draft->load('product', 'files'),
        ], 201);
    }

    public function updateDraft(Request $request, ProductCustomizationDraft $draft)
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
            'selected_options' => ['nullable', 'array'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);

        $pricing = $this->calculatePrice($draft->product, array_merge($draft->toArray(), $validated), $request->user()?->id ?: $draft->user_id);
        $draft->update([
            'selected_options' => $validated['selected_options'] ?? $draft->selected_options ?? [],
            'quantity' => $pricing['quantity'],
            'unit_price' => $pricing['unit_price'],
            'setup_fee' => $pricing['setup_fee'],
            'discount_amount' => $pricing['discount_amount'],
            'total_price' => $pricing['total_price'],
            'coupon_code' => $pricing['coupon_code'],
            'metadata' => array_merge($draft->metadata ?? [], $validated['metadata'] ?? []),
        ]);

        return response()->json([
            'success' => true,
            'data' => $draft->fresh()->load('product', 'files'),
        ]);
    }

    public function uploadFile(Request $request, ProductCustomizationDraft $draft)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,ai,eps,webp', 'max:10240'],
            'file_type' => ['nullable', 'string', 'max:50'],
        ]);

        $file = $request->file('file');
        $path = $file->store('product-customizations/' . $draft->id, 'public');

        $record = $draft->files()->create([
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'file_type' => $request->input('file_type', 'logo'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $record,
        ], 201);
    }

    public function submitOrder(Request $request, ProductCustomizationDraft $draft)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($request, $draft, $validated) {
            $product = $draft->product;
            $customization = $this->customizationPayload($draft);
            $userId = optional($request->user())->id ?? $draft->user_id;
            $shippingAmount = (float) ($validated['shipping_amount'] ?? 0);

            $pricing = $this->calculatePrice($product, array_merge($draft->toArray(), [
                'quantity' => $draft->quantity,
                'coupon_code' => $draft->coupon_code,
            ]), $userId);

            $appliedCoupon = $draft->coupon_code
                ? EcommerceCoupon::where('code', $draft->coupon_code)->first()
                : null;
            $couponContext = [
                'product_ids' => [$product->id],
                'user_id' => $userId,
                'customer_email' => $validated['customer_email'],
            ];

            if ($draft->coupon_code && (! $appliedCoupon || ! $appliedCoupon->isUsableFor((float) ($pricing['subtotal']), $couponContext))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coupon is not valid for this order.',
                ], 422);
            }

            if ($appliedCoupon?->discount_type === 'free_shipping') {
                $shippingAmount = max(0, $shippingAmount - $appliedCoupon->shippingDiscountFor($shippingAmount, (float) ($pricing['subtotal']), $couponContext));
            }

            // Membership Free Delivery Waiver check
            $membershipBenefits = $pricing['membership_benefits'] ?? null;
            if (!empty($membershipBenefits['membership_benefit_usage'])) {
                foreach ($membershipBenefits['membership_benefit_usage'] as $usage) {
                    if (in_array($usage['type'] ?? '', ['free_delivery', 'free_shipping'])) {
                        $shippingAmount = 0.0;
                    }
                }
            }

            $order = EcommerceOrder::create([
                'order_number' => EcommerceOrder::generateOrderNumber(),
                'user_id' => $userId,
                'customer_name' => $validated['customer_name'],
                'company_name' => $validated['company_name'] ?? null,
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $validated['customer_phone'] ?? null,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => $validated['payment_method'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'shipping_method' => $validated['shipping_method'] ?? null,
                'shipping_amount' => $shippingAmount,
                'membership_id' => $membershipBenefits['membership_id'] ?? null,
                'membership_plan_name' => $membershipBenefits['membership_plan_name'] ?? null,
                'membership_discount_amount' => $pricing['membership_discount_amount'] ?? 0.0,
                'membership_benefits_snapshot' => $membershipBenefits['membership_benefits_snapshot'] ?? null,
                'membership_benefit_usage' => $membershipBenefits['membership_benefit_usage'] ?? null,
                'metadata' => [
                    'customization' => $customization,
                    'draft_id' => $draft->id,
                    'pricing_breakdown' => $pricing['breakdown'] ?? null,
                ],
                'total_amount' => max(0.00, round($pricing['total_price'] + $shippingAmount, 2)),
                'subtotal' => $pricing['subtotal'],
                'discount_amount' => $pricing['discount_amount'],
                'order_date' => Carbon::today(),
            ]);
            $order->statusEvents()->create([
                'user_id' => $userId,
                'status' => 'pending',
                'label' => 'Order submitted',
            ]);

            EcommerceOrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => $pricing['quantity'],
                'unit_price' => $pricing['unit_price'],
                'total_price' => $pricing['total_price'],
                'product_options' => $customization,
            ]);

            if ($appliedCoupon) {
                $appliedCoupon->increment('used_count');
            }

            $draft->update(['status' => 'converted']);

            app(EmailNotificationService::class)->sendOrderEvent('order_placed', $order->fresh('items'));

            return response()->json([
                'success' => true,
                'message' => 'Order submitted successfully.',
                'data' => ['order' => $order->load('items')],
            ], 201);
        });
    }

    public function submitQuote(Request $request, ProductCustomizationDraft $draft)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $quote = EcommerceQuotation::create([
            'quote_number' => 'QUO-' . now()->format('Y') . '-' . strtoupper(Str::random(6)),
            'user_id' => optional($request->user())->id ?? $draft->user_id,
            'product_id' => $draft->product_id,
            'company_name' => $validated['company_name'] ?? null,
            'customer_name' => $validated['customer_name'],
            'contact_email' => $validated['customer_email'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'quantity' => $draft->quantity,
            'customization' => $this->customizationPayload($draft),
            'metadata' => [
                'notes' => $validated['notes'] ?? null,
                'draft_id' => $draft->id,
            ],
            'status' => 'pending',
            'total_estimated' => $draft->total_price,
            'valid_until' => now()->addDays(14)->toDateString(),
        ]);

        $draft->update(['status' => 'submitted']);

        app(EmailNotificationService::class)->sendEvent('quote_submitted', [
            'customer_name' => $quote->customer_name,
            'customer_email' => $quote->customer_email ?: $quote->contact_email,
            'quote_number' => $quote->quote_number,
            'quote_total' => '$' . number_format((float) $quote->total_estimated, 2),
            'site_name' => config('app.name', 'Mecarvi Embroidery'),
        ], $quote->customer_email ?: $quote->contact_email);

        return response()->json([
            'success' => true,
            'message' => 'Quote request submitted successfully.',
            'data' => ['quotation' => $quote],
        ], 201);
    }

    public function coupons(Product $product)
    {
        $coupons = EcommerceCoupon::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->where(function ($query) use ($product) {
                $query->whereDoesntHave('products')
                    ->orWhereHas('products', fn ($productQuery) => $productQuery->where('products.id', $product->id));
            })
            ->with('products:id')
            ->orderBy('id')
            ->get()
            // Same rule checkout uses, so an excluded product never shows an offer it cannot get
            ->filter(fn (EcommerceCoupon $coupon) => $coupon->appliesToProducts([$product->id]))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $coupons->map(fn (EcommerceCoupon $coupon) => $coupon->toPublicArray())->values(),
        ]);
    }

    public function related(Product $product)
    {
        $related = $product->relatedProducts()
            ->where('is_active', true)
            ->orderBy('product_related_products.sort_order')
            ->get();

        if ($related->isEmpty()) {
            $related = Product::query()
                ->where('is_active', true)
                ->where('id', '!=', $product->id)
                ->where('category_id', $product->category_id)
                ->latest()
                ->limit(8)
                ->get();
        }

        return response()->json(['success' => true, 'data' => $related]);
    }

    public function calculatePrice(Product $product, array $payload, ?int $userId = null): array
    {
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $unitPrice = (float) ($product->sale_price ?? $product->price ?? 0);
        $setupFee = (float) data_get($product->attributes, 'setup_fee', 0);
        $selectedOptions = $payload['selected_options'] ?? [];
        $optionAdjustments = $this->optionAdjustments($product, $selectedOptions, $quantity);
        $ruleAdjustments = $this->ruleAdjustments($product, $selectedOptions, $quantity, $unitPrice);
        $lineSubtotal = ($unitPrice * $quantity) + $optionAdjustments['total'] + $ruleAdjustments['total'];
        $subtotal = round(max(0, $lineSubtotal + $setupFee), 2);
        $couponCode = isset($payload['coupon_code']) ? strtoupper(trim((string) $payload['coupon_code'])) : null;
        $discount = 0;
        $appliedCoupon = null;

        if ($couponCode) {
            $coupon = EcommerceCoupon::where('code', $couponCode)
                ->where(function ($query) use ($product) {
                    $query->whereDoesntHave('products')
                        ->orWhereHas('products', fn ($productQuery) => $productQuery->where('products.id', $product->id));
                })
                ->first();

            $context = ['product_ids' => [$product->id]];
            if ($coupon && $coupon->isUsableFor($subtotal, $context)) {
                $discount = $coupon->discountFor($subtotal, $context);
                $appliedCoupon = $coupon;
            }
        }

        // Evaluate Membership Benefits (10% Essentials, 20% Business Pro, etc.)
        $membershipDiscount = 0.0;
        $membershipBenefits = null;
        if ($userId) {
            $memberships = \App\Models\EcommerceMembership::where('user_id', $userId)
                ->whereIn('status', ['Active', 'active', 'trialing', 'pending_cancellation'])
                ->get()
                ->map(function ($m) {
                    $arr = $m->toArray();
                    if (empty($arr['benefits_snapshot']) && empty($arr['benefits'])) {
                        $plan = ($m->plan_id ? \App\Models\EcommerceSubscriptionPlan::find($m->plan_id) : null)
                            ?: \App\Models\EcommerceSubscriptionPlan::where('name', $m->plan_name)
                                ->orWhere('internal_code', $m->plan_name)
                                ->first();
                        if ($plan) {
                            $arr['benefits_snapshot'] = $plan->benefit_config ?: $plan->features;
                        }
                    }
                    return $arr;
                })->toArray();

            if (!empty($memberships)) {
                $membershipBenefits = app(\App\Services\MembershipBenefitService::class)->evaluate(
                    $memberships,
                    $subtotal,
                    0.0,
                    config('services.mccarvy_site.slug', 'embroidery'),
                    $appliedCoupon !== null
                );
                if (!empty($membershipBenefits['membership_discount_amount'])) {
                    $membershipDiscount = (float) $membershipBenefits['membership_discount_amount'];
                }
            }
        }

        $totalDiscount = round($discount + $membershipDiscount, 2);

        return [
            'quantity' => $quantity,
            'unit_price' => round($unitPrice, 2),
            'setup_fee' => round($setupFee, 2),
            'option_adjustments' => round($optionAdjustments['total'], 2),
            'pricing_rule_adjustments' => round($ruleAdjustments['total'], 2),
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'membership_discount_amount' => $membershipDiscount,
            'membership_benefits' => $membershipBenefits,
            'total_price' => round(max(0, $subtotal - $totalDiscount), 2),
            'coupon_code' => $appliedCoupon ? $appliedCoupon->code : null,
            'coupon' => $appliedCoupon ? $appliedCoupon->toPublicArray() : null,
            'breakdown' => [
                'base' => round($unitPrice * $quantity, 2),
                'options' => $optionAdjustments['items'],
                'rules' => $ruleAdjustments['items'],
                'setup_fee' => round($setupFee, 2),
                'coupon_discount' => round($discount, 2),
                'membership_discount' => round($membershipDiscount, 2),
                'discount' => $totalDiscount,
            ],
        ];
    }

    private function optionAdjustments(Product $product, array $selectedOptions, int $quantity): array
    {
        $items = [];
        $total = 0;

        $options = $product->customizationOptions()
            ->where('is_active', true)
            ->get()
            ->groupBy('option_type');

        // Fetch active global attributes with active values
        $globalAttrs = \App\Models\GlobalAttribute::with(['values' => function ($q) {
            $q->where('status', 'active');
        }])->where('status', 'active')->get();

        foreach ($selectedOptions as $type => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value) && ! array_is_list($value)) {
                continue;
            }
            $values = is_array($value) ? $value : [$value];
            $typeKey = $this->optionKey($type);

            foreach ($values as $selectedValue) {
                if ($selectedValue === null || $selectedValue === '' || is_array($selectedValue) || is_object($selectedValue)) {
                    continue;
                }
                $valKey = $this->optionKey($selectedValue);

                // 1. Try product-specific customization options
                $group = $options->get($type);
                if ($group) {
                    $match = $group->first(function ($option) use ($valKey, $selectedValue) {
                        return $option->option_key === $valKey || $option->label === $selectedValue;
                    });

                    if ($match) {
                        $amount = round((float) $match->price_modifier * $quantity, 2);
                        $total += $amount;
                        $items[] = [
                            'type' => $type,
                            'key' => $match->option_key,
                            'label' => $match->label,
                            'amount' => $amount,
                        ];
                        continue;
                    }
                }

                // 2. Try global attributes
                $globalAttr = $globalAttrs->first(function ($attr) use ($typeKey, $type) {
                    return $this->optionKey($attr->name) === $typeKey || strtolower($attr->name) === strtolower($type);
                });

                if ($globalAttr) {
                    $matchVal = $globalAttr->values->first(function ($val) use ($valKey, $selectedValue) {
                        return $this->optionKey($val->name) === $valKey || strtolower($val->name) === strtolower($selectedValue);
                    });

                    if ($matchVal) {
                        $pricingMode = $matchVal->pricing_mode ?? $globalAttr->pricing_mode ?? 'per_item';
                        $isPerItem = $pricingMode === 'per_item';
                        $amount = round((float) $matchVal->price * ($isPerItem ? $quantity : 1), 2);
                        $total += $amount;
                        $items[] = [
                            'type' => $globalAttr->name,
                            'key' => $this->optionKey($matchVal->name),
                            'label' => $matchVal->name,
                            'amount' => $amount,
                        ];
                    }
                }
            }
        }

        return ['total' => $total, 'items' => $items];
    }

    private function ruleAdjustments(Product $product, array $selectedOptions, int $quantity, float $unitPrice): array
    {
        $items = [];
        $total = 0;

        $rules = $product->pricingRules()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->min_quantity !== null && $quantity < (int) $rule->min_quantity) {
                continue;
            }

            if ($rule->max_quantity !== null && $quantity > (int) $rule->max_quantity) {
                continue;
            }

            if ($rule->option_type && ! $this->selectedOptionMatches($selectedOptions, $rule->option_type, $rule->option_key)) {
                continue;
            }

            $basis = $rule->rule_type === 'quantity' ? $unitPrice * $quantity : $unitPrice;
            $amount = $rule->adjustment_type === 'percentage'
                ? round($basis * ((float) $rule->adjustment_value / 100), 2)
                : round((float) $rule->adjustment_value, 2);

            $total += $amount;
            $items[] = [
                'rule_type' => $rule->rule_type,
                'adjustment_type' => $rule->adjustment_type,
                'adjustment_value' => (float) $rule->adjustment_value,
                'amount' => $amount,
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    private function selectedOptionMatches(array $selectedOptions, string $type, ?string $key): bool
    {
        if (! array_key_exists($type, $selectedOptions)) {
            return false;
        }

        if (! $key) {
            return true;
        }

        $raw = $selectedOptions[$type];
        $values = is_array($raw) ? (array_is_list($raw) ? $raw : array_keys($raw)) : [$raw];

        return collect($values)->contains(function ($value) use ($key) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
            return $this->optionKey($value) === $key || (string) $value === $key;
        });
    }

    private function optionKey(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return Str::of(json_encode($value))->lower()->slug('_')->toString();
        }
        return Str::of((string) $value)->lower()->slug('_')->toString();
    }

    private function customizationPayload(ProductCustomizationDraft $draft): array
    {
        return [
            'selected_options' => $draft->selected_options ?? [],
            'quantity' => $draft->quantity,
            'coupon_code' => $draft->coupon_code,
            'files' => $draft->files->map(fn ($file) => [
                'path' => $file->file_path,
                'original_name' => $file->original_name,
                'file_type' => $file->file_type,
            ])->values()->all(),
        ];
    }

    private function sessionId(Request $request): string
    {
        return $request->header('X-Session-Id')
            ?: $request->header('X-Guest-Session')
            ?: (string) Str::uuid();
    }
}
