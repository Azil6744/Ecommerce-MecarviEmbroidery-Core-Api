<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\User;
use App\Models\EcommerceCart;
use App\Models\EcommerceAddress;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderItem;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceCoupon;
use App\Services\EmailNotificationService;
use App\Services\MembershipBenefitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class CheckoutController extends Controller
{
    /**
     * Process the checkout request and generate an order.
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'items' => 'nullable|array',
            'shipping_address_id' => 'nullable',
            'billing_address_id' => 'nullable',
            'shipping_method' => 'nullable|string|max:255',
            'payment_method' => 'required|string|max:255',
            'shipping_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'tip_amount' => 'nullable|numeric|min:0',
            'donation_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'packaging_option' => 'nullable|string|max:255',
            'packaging_mode' => 'nullable|string|max:50',
            'packaging_style' => 'nullable|string|max:100',
            'packages' => 'nullable|array',
            'additional_packaging_options' => 'nullable|array',
            'gift_message' => 'nullable|string',
            'gift_card_codes' => 'nullable|array',
            'gift_card_codes.*' => 'string',
            'pay_with_points_item_ids' => 'nullable|array',
            'pay_with_points_item_ids.*' => 'integer',
            'points_redeemed' => 'nullable|integer|min:0',
            'selected_charity' => 'nullable|string|max:255',
            'charity_donations' => 'nullable|array',
            'donations' => 'nullable|array',
            'donation_note' => 'nullable|string',
            'packaging_amount' => 'nullable|numeric|min:0',
            'item_packaging_configs' => 'nullable|array',
            'add_thank_you_card' => 'nullable|boolean',
            'add_extra_protection' => 'nullable|boolean',
            'add_package_insurance' => 'nullable|boolean',
            'gift_details' => 'nullable|array',
            'recipient_name' => 'nullable|string|max:255',
            'card_orientation' => 'nullable|string|max:50',
            'additional_instructions' => 'nullable|string',
            'pickup_location_id' => 'nullable',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'guest_shipping_address' => 'nullable|array',
            'guest_billing_address' => 'nullable|array',
        ]);

        try {
            DB::beginTransaction();

            $user = $request->user();
            $cart = EcommerceCart::with('items.product')
                ->where('user_id', $user?->id)
                ->where('status', 'active')
                ->first();

            if (! $user) {
                $emailFromRequest = strtolower(trim((string) ($request->input('customer_email') ?? '')));
                if ($emailFromRequest !== '') {
                    $user = \App\Models\User::whereRaw('LOWER(email) = ?', [$emailFromRequest])->first();
                }
            }

            $cartItems = $cart?->items ?? collect();
            $requestItems = collect($validated['items'] ?? []);

            if ($cartItems->isEmpty() && $requestItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty.',
                ], 422);
            }

            // 1. Fetch settings and calculate points-to-dollar discount ratio
            $settings = \App\Models\SiteSetting::first();
            $ratio = 0.01;
            $loyaltyEnabled = true;
            $loyalty = null;
            if ($settings && $settings->loyalty_settings) {
                $loyalty = json_decode($settings->loyalty_settings, true);
                if (isset($loyalty['enabled'])) {
                    $loyaltyEnabled = filter_var($loyalty['enabled'], FILTER_VALIDATE_BOOLEAN);
                }
                $ratio = (float)($loyalty['points_to_dollar_ratio'] ?? 0.01);
            }

            // Block points redemptions if loyalty is disabled
            if (!$loyaltyEnabled && ($request->input('points_redeemed') > 0 || !empty($request->input('pay_with_points_item_ids')))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Loyalty rewards program is currently disabled.'
                ], 400);
            }

            $pointsRedeemed = 0;
            $generalPointsRedeemed = 0;
            $itemPointsRedeemed = 0;

            // Calculate points needed for pay_with_points items
            if ($user && !empty($validated['pay_with_points_item_ids'])) {
                $pointsItemIds = $validated['pay_with_points_item_ids'];
                $pointsProducts = \App\Models\Product::whereIn('id', $pointsItemIds)->get()->keyBy('id');

                if ($cartItems->isNotEmpty()) {
                    foreach ($cartItems as $cartItem) {
                        if (in_array($cartItem->product_id, $pointsItemIds)) {
                            $prod = $pointsProducts->get($cartItem->product_id);
                            if ($prod && $prod->loyalty_points_price) {
                                $itemPointsRedeemed += $prod->loyalty_points_price * $cartItem->quantity;
                                $cartItem->total_price = 0.00;
                                $cartItem->unit_price = 0.00;
                            }
                        }
                    }
                } else {
                    $updatedRequestItems = [];
                    foreach ($requestItems as $item) {
                        $productId = $item['product_id'] ?? null;
                        if ($productId && in_array($productId, $pointsItemIds)) {
                            $prod = $pointsProducts->get($productId);
                            if ($prod && $prod->loyalty_points_price) {
                                $qty = (int)($item['quantity'] ?? 1);
                                $itemPointsRedeemed += $prod->loyalty_points_price * $qty;
                                $item['total_price'] = 0.00;
                                $item['unit_price'] = 0.00;
                                $item['price'] = 0.00;
                            }
                        }
                        $updatedRequestItems[] = $item;
                    }
                    $requestItems = collect($updatedRequestItems);
                }
                $pointsRedeemed += $itemPointsRedeemed;
            }

            // Calculate points needed for general subtotal discount
            if ($user && !empty($validated['points_redeemed'])) {
                $generalPointsRedeemed = (int)$validated['points_redeemed'];
                $pointsRedeemed += $generalPointsRedeemed;
            }

            // Validation moved below where variables are resolved

            $shippingAmount = round((float) ($validated['shipping_amount'] ?? 0), 2);
            $couponDiscount = round((float) ($validated['discount_amount'] ?? 0), 2);

            $itemsSubtotal = $cartItems->isNotEmpty()
                ? (float) $cartItems->sum('total_price')
                : (float) $requestItems->sum(fn ($item) => (float) ($item['total_price'] ?? (($item['unit_price'] ?? $item['price'] ?? 0) * ($item['quantity'] ?? 1))));

            $productIds = $cartItems->isNotEmpty()
                ? $cartItems->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->values()->all()
                : $requestItems->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
            $couponContext = [
                'product_ids' => $productIds,
                'user_id' => $user?->id,
                'customer_email' => $user?->email ?? $request->input('customer_email'),
            ];

            $appliedCoupon = null;
            $couponCode = strtoupper(trim((string) ($validated['coupon_code'] ?? '')));
            if ($couponCode !== '') {
                $coupon = EcommerceCoupon::query()
                    ->with('products:id')
                    ->whereRaw('UPPER(code) = ?', [$couponCode])
                    ->first();

                if (! $coupon || ! $coupon->isUsableFor($itemsSubtotal, $couponContext)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Coupon is not valid for this order.',
                    ], 422);
                }

                $appliedCoupon = $coupon;
                $couponDiscount = max($couponDiscount, $coupon->discountFor($itemsSubtotal, $couponContext));
            } else {
                $appliedCoupon = $this->bestAutoCoupon($itemsSubtotal, $couponContext);
                if ($appliedCoupon) {
                    $couponCode = $appliedCoupon->code;
                    $couponDiscount = max($couponDiscount, $appliedCoupon->discountFor($itemsSubtotal, $couponContext));
                }
            }

            if ($appliedCoupon?->discount_type === 'free_shipping') {
                $shippingAmount = max(0, round($shippingAmount - $appliedCoupon->shippingDiscountFor($shippingAmount, $itemsSubtotal, $couponContext), 2));
            }

            // Calculate loyalty points discount value
            $loyaltyPointsDiscount = round($generalPointsRedeemed * $ratio, 2);
            $totalDiscount = round($couponDiscount + $loyaltyPointsDiscount, 2);
            $membershipBenefits = app(MembershipBenefitService::class)->evaluate(
                $this->activeCentralMemberships($request),
                $itemsSubtotal,
                $shippingAmount,
                config('services.mccarvy_site.slug', 'embroidery'),
                $appliedCoupon !== null
            );

            if ($membershipBenefits['membership_discount_amount'] > 0) {
                $totalDiscount = round($totalDiscount + $membershipBenefits['membership_discount_amount'], 2);
            }

            // Resolve Shipping and Billing Addresses for location-based taxes and checkout
            $shippingAddress = null;
            if (!empty($validated['shipping_address_id']) && is_numeric($validated['shipping_address_id'])) {
                $shippingAddress = $this->addressForUser($user?->id, $validated['shipping_address_id']);
            }
            if (!$shippingAddress && !empty($validated['guest_shipping_address'])) {
                $shippingAddress = $validated['guest_shipping_address'];
            }
            if (!$shippingAddress && !empty($validated['shipping_address'])) {
                $shippingAddress = $validated['shipping_address'];
            }

            if ($user && $shippingAddress) {
                $this->saveAddressForUserIfMissing($user, $shippingAddress);
            }

            $billingAddress = null;
            if (!empty($validated['billing_address_id'])) {
                $billingAddress = $this->addressForUser($user?->id, $validated['billing_address_id']);
            } else if (!empty($validated['guest_billing_address'])) {
                $billingAddress = $validated['guest_billing_address'];
            } else if (!empty($validated['guest_shipping_address'])) {
                $billingAddress = $validated['guest_shipping_address'];
            }

            // Location-based Tax Calculation
            $taxSettings = $settings && $settings->tax_settings ? json_decode($settings->tax_settings, true) : [];
            $taxCalculationBasedOn = $taxSettings['tax_calculation_based_on'] ?? 'shipping_address';

            $targetAddress = ($taxCalculationBasedOn === 'billing_address' && $billingAddress)
                ? $billingAddress
                : ($shippingAddress ?: $billingAddress);

            $targetState = null;
            if (is_array($targetAddress)) {
                $targetState = $targetAddress['state'] ?? $targetAddress['region'] ?? $targetAddress['province'] ?? null;
            } elseif (is_object($targetAddress)) {
                $targetState = $targetAddress->state ?? $targetAddress->region ?? null;
            }
            if (!$targetState && !empty($validated['state'])) {
                $targetState = $validated['state'];
            }

            $taxCalculation = app(\App\Services\TaxCalculationService::class)->calculate([
                'state' => $targetState,
                'country' => 'US',
                'subtotal' => $itemsSubtotal,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $totalDiscount,
                'is_tax_exempt' => !empty($validated['is_tax_exempt']),
                'user' => $user,
            ]);

            $taxRate = (float)($taxCalculation['tax_rate'] ?? 0.00);
            $taxAmount = (float)($taxCalculation['tax_amount'] ?? 0.00);

            $tipAmount = round((float) ($validated['tip_amount'] ?? 0), 2);
            $donationAmount = round((float) ($validated['donation_amount'] ?? 0), 2);
            $packagingAmount = round((float) ($validated['packaging_amount'] ?? 0), 2);

            $charityEnabled = $settings ? (bool)$settings->charity_donation_enabled : true;
            if ($donationAmount > 0 && !$charityEnabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Charity donations are currently disabled.',
                ], 400);
            }

            // Subtotal + shipping + tax + tip + donation + packaging - totalDiscount
            $originalTotalAmount = round((float) ($itemsSubtotal + $shippingAmount + $taxAmount + $tipAmount + $donationAmount + $packagingAmount - $totalDiscount), 2);
            $totalAmount = max(0.00, $originalTotalAmount);

            // Gift card validation
            $giftCardCodes = $validated['gift_card_codes'] ?? [];

            // If user is redeeming points, validate limits and balance
            if ($pointsRedeemed > 0) {
                if (!$loyaltyEnabled) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Loyalty rewards program is currently disabled.'
                    ], 400);
                }

                // Enforce minimum points required to redeem
                $minRedeemPoints = (int)($loyalty['minimum_redeem_points'] ?? 500);
                if ($generalPointsRedeemed > 0 && $generalPointsRedeemed < $minRedeemPoints) {
                    return response()->json([
                        'success' => false,
                        'message' => "Minimum points required to redeem is {$minRedeemPoints} points."
                    ], 400);
                }

                // Enforce minimum order amount to redeem
                $minOrderAmount = (float)($loyalty['min_order_amount'] ?? 25.00);
                if ($generalPointsRedeemed > 0 && $itemsSubtotal < $minOrderAmount) {
                    return response()->json([
                        'success' => false,
                        'message' => "Minimum order subtotal to redeem points is $" . number_format($minOrderAmount, 2) . "."
                    ], 400);
                }

                // Enforce combinability with promo code/coupons
                $allowWithCoupons = filter_var($loyalty['allow_with_coupons'] ?? true, FILTER_VALIDATE_BOOLEAN);
                if ($generalPointsRedeemed > 0 && !empty($couponCode) && !$allowWithCoupons) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Loyalty points cannot be combined with coupon codes.'
                    ], 400);
                }

                // Enforce combinability with gift cards
                $allowWithGiftCards = filter_var($loyalty['allow_with_gift_cards'] ?? true, FILTER_VALIDATE_BOOLEAN);
                if ($generalPointsRedeemed > 0 && !empty($giftCardCodes) && !$allowWithGiftCards) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Loyalty points cannot be combined with gift cards.'
                    ], 400);
                }

                // Validate points balance
                $centralUrl = env('CENTRAL_AUTH_URL', 'http://localhost:8000/api');
                $token = $request->header('X-Central-Auth-Token') ?? $request->bearerToken();
                $currentPoints = (int) $user->loyalty_points;

                if ($token) {
                    try {
                        $loyaltyRes = \Illuminate\Support\Facades\Http::acceptJson()
                            ->withToken($token)
                            ->timeout(3)
                            ->get($centralUrl . '/user/loyalty');
                        if ($loyaltyRes->successful()) {
                            $currentPoints = (int) $loyaltyRes->json('data.points');
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Checkout failed to fetch central user points: ' . $e->getMessage());
                    }
                }

                if ($currentPoints < $pointsRedeemed) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient loyalty points. You need {$pointsRedeemed} points but only have {$currentPoints}."
                    ], 400);
                }

                // Enforce maximum discount percentage
                $maxRedeemPercent = (float)($loyalty['max_redeem_percent'] ?? 25.00);
                $maxDiscountAmount = round($itemsSubtotal * ($maxRedeemPercent / 100), 2);
                if ($loyaltyPointsDiscount > $maxDiscountAmount) {
                    return response()->json([
                        'success' => false,
                        'message' => "Loyalty points discount cannot exceed {$maxRedeemPercent}% of the subtotal (max discount of $" . number_format($maxDiscountAmount, 2) . ")."
                    ], 400);
                }
            }
            $giftCardsToRedeem = [];
            $totalGiftCardApplied = 0.00;

            if (!empty($giftCardCodes)) {
                foreach ($giftCardCodes as $code) {
                    $giftCard = EcommerceGiftCard::where('code', $code)->first();

                    if (!$giftCard) {
                        return response()->json([
                            'success' => false,
                            'message' => "Invalid gift card code: {$code}"
                        ], 400);
                    }

                    if (in_array(strtolower($giftCard->status), ['disabled', 'expired', 'fully used', 'redeemed', 'cancelled'])) {
                        return response()->json([
                            'success' => false,
                            'message' => "Gift card {$code} is not active."
                        ], 400);
                    }

                    if ($giftCard->expires_at && $giftCard->expires_at->isPast()) {
                        return response()->json([
                            'success' => false,
                            'message' => "Gift card {$code} has expired."
                        ], 400);
                    }

                    if ($giftCard->current_balance <= 0) {
                        return response()->json([
                            'success' => false,
                            'message' => "Gift card {$code} has no remaining balance."
                        ], 400);
                    }

                    $giftCardsToRedeem[] = $giftCard;
                }

                // Calculate total gift card amount to apply
                $tempTotal = $totalAmount;
                foreach ($giftCardsToRedeem as $giftCard) {
                    $cardBalance = (float) $giftCard->current_balance;
                    $deduct = min($tempTotal, $cardBalance);
                    $tempTotal -= $deduct;
                    $totalGiftCardApplied += $deduct;

                    if ($tempTotal <= 0) {
                        break;
                    }
                }

                // Deduct from total amount
                $totalAmount = max(0.00, round($totalAmount - $totalGiftCardApplied, 2));
            }


            // 3. Award Loyalty Points on eligible subtotal paid amount
            $pointsEarned = 0;
            if ($user && $loyaltyEnabled) {
                $includeTax = false;
                $includeShipping = false;
                if ($settings && $settings->loyalty_settings) {
                    $includeTax = filter_var($loyalty['include_tax_in_calculation'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $includeShipping = filter_var($loyalty['include_shipping_in_calculation'] ?? false, FILTER_VALIDATE_BOOLEAN);
                }

                $eligibleAmount = $itemsSubtotal - $totalDiscount;
                if ($includeShipping) {
                    $eligibleAmount += $shippingAmount;
                }
                if ($includeTax) {
                    $eligibleAmount += $taxAmount;
                }
                $eligibleAmount = max(0.00, $eligibleAmount);

                if ($eligibleAmount > 0) {
                    $pointsPerDollar = (float)($loyalty['points_per_dollar'] ?? 1.0);
                    if ($pointsPerDollar <= 0) {
                        $pointsPerDollar = 1.0;
                    }
                    $pointsEarned = (int) round($eligibleAmount * $pointsPerDollar);
                }
            }

            $isWalletPayment = strtolower((string)($validated['payment_method'] ?? '')) === 'wallet';

            $pickupLocationId = null;
            if (!empty($validated['pickup_location_id']) && is_numeric($validated['pickup_location_id'])) {
                $exists = \App\Models\StorePickupLocation::where('id', (int)$validated['pickup_location_id'])->exists();
                if ($exists) {
                    $pickupLocationId = (int)$validated['pickup_location_id'];
                }
            }

            $orderData = [
                'user_id' => $user?->id,
                'customer_name' => $user?->name ?? $validated['customer_name'] ?? 'Guest Customer',
                'customer_email' => $user?->email ?? $validated['customer_email'] ?? 'guest@example.com',
                'status' => 'pending',
                'payment_status' => $isWalletPayment
                    ? 'unpaid'
                    : ($request->input('payment_status') ?: (in_array(strtolower((string)($validated['payment_method'] ?? '')), ['stripe', 'saved_card', 'paypal', 'apple_pay', 'google_pay', 'cashapp', 'card']) ? 'paid' : 'unpaid')),
                'total_amount' => $totalAmount,
                'subtotal' => round($itemsSubtotal, 2),
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $totalDiscount,
                'membership_id' => $membershipBenefits['membership_id'],
                'membership_plan_name' => $membershipBenefits['membership_plan_name'],
                'membership_discount_amount' => $membershipBenefits['membership_discount_amount'],
                'membership_benefits_snapshot' => $membershipBenefits['membership_benefits_snapshot'],
                'membership_benefit_usage' => $membershipBenefits['membership_benefit_usage'],
                'tax_amount' => $taxAmount,
                'tip_amount' => $tipAmount,
                'donation_amount' => $donationAmount,
                'loyalty_points_earned' => $pointsEarned,
                'loyalty_points_redeemed' => $pointsRedeemed,
                'shipping_address' => EcommerceOrderController::formatAddress($shippingAddress),
                'billing_address' => EcommerceOrderController::formatAddress($billingAddress),
                'payment_method' => $validated['payment_method'],
                'shipping_method' => $validated['shipping_method'] ?? null,
                'pickup_location_id' => $pickupLocationId,
                'notes' => $validated['notes'] ?? null,
                'metadata' => [
                    'packaging_option' => $validated['packaging_option'] ?? null,
                    'packaging_mode' => $validated['packaging_mode'] ?? 'together',
                    'packaging_style' => $validated['packaging_style'] ?? 'premium',
                    'packages' => $validated['packages'] ?? null,
                    'additional_packaging_options' => $validated['additional_packaging_options'] ?? null,
                    'packaging_amount' => $packagingAmount ?? 0,
                    'item_packaging_configs' => $validated['item_packaging_configs'] ?? null,
                    'add_thank_you_card' => $validated['add_thank_you_card'] ?? false,
                    'add_extra_protection' => $validated['add_extra_protection'] ?? false,
                    'add_package_insurance' => $validated['add_package_insurance'] ?? false,
                    'gift_details' => $validated['gift_details'] ?? [
                        'recipient_name' => $validated['recipient_name'] ?? null,
                        'special_message' => $validated['gift_message'] ?? null,
                        'card_orientation' => $validated['card_orientation'] ?? 'horizontal',
                        'additional_instructions' => $validated['additional_instructions'] ?? null,
                    ],
                    'recipient_name' => $validated['recipient_name'] ?? ($validated['gift_details']['recipient_name'] ?? null),
                    'gift_message' => $validated['gift_message'] ?? ($validated['gift_details']['special_message'] ?? ($validated['gift_details']['message'] ?? null)),
                    'card_orientation' => $validated['card_orientation'] ?? ($validated['gift_details']['card_orientation'] ?? 'horizontal'),
                    'additional_instructions' => $validated['additional_instructions'] ?? ($validated['gift_details']['additional_instructions'] ?? null),
                    'coupon_code' => $appliedCoupon?->code,
                    'coupon' => $appliedCoupon?->toManagementArray(),
                    'membership_benefits' => $membershipBenefits,
                    'tax_rate' => $taxRate,
                    'tax_breakdown' => $taxCalculation,
                    'checkout_payload' => $validated,
                ],
                'order_number' => EcommerceOrder::generateOrderNumber(),
                'order_date' => Carbon::today(),
            ];

            $order = EcommerceOrder::create($orderData);

            if ($appliedCoupon) {
                $appliedCoupon->increment('used_count');
            }

            // Log Donation transaction(s) in the database if donation was made
            $rawDonations = $validated['charity_donations'] ?? ($validated['donations'] ?? null);
            $donationsList = [];

            if (is_array($rawDonations) && count($rawDonations) > 0) {
                foreach ($rawDonations as $item) {
                    $amt = round((float) ($item['amount'] ?? 0), 2);
                    if ($amt > 0) {
                        $cName = $item['charity_name'] ?? ($item['name'] ?? 'Mecarvi Foundation');
                        $donationsList[] = [
                            'charity_name' => $cName,
                            'amount' => $amt,
                            'note' => $item['note'] ?? ($item['donation_note'] ?? null),
                            'charity_category' => $item['charity_category'] ?? ($item['category'] ?? null),
                            'charity_logo_type' => $item['charity_logo_type'] ?? ($item['logoSvgType'] ?? ($item['logo_svg_type'] ?? null)),
                        ];
                    }
                }
            }

            // Fallback for single donation amount / selected charity if charity_donations array was empty but donationAmount > 0
            if (empty($donationsList) && $donationAmount > 0) {
                $donationsList[] = [
                    'charity_name' => $validated['selected_charity'] ?? 'Mecarvi Foundation',
                    'amount' => $donationAmount,
                    'note' => $validated['donation_note'] ?? null,
                    'charity_category' => null,
                    'charity_logo_type' => null,
                ];
            }

            if (!empty($donationsList)) {
                $donorName = $user ? $user->name : ($order->customer_name ?? 'Guest Customer');
                $donorEmail = $user ? $user->email : ($order->customer_email ?? 'guest@example.com');

                $pmBrand = 'Visa';
                $pmDetails = 'Card';
                $methodLower = strtolower((string)($validated['payment_method'] ?? ''));
                if ($methodLower === 'paypal') {
                    $pmBrand = 'PayPal';
                    $pmDetails = 'PayPal Account';
                } else if ($methodLower === 'apple_pay') {
                    $pmBrand = 'Apple Pay';
                    $pmDetails = 'Apple Pay';
                } else if ($methodLower === 'google_pay') {
                    $pmBrand = 'Google Pay';
                    $pmDetails = 'Google Pay';
                } else if ($methodLower === 'saved_card') {
                    $pmBrand = 'Visa';
                    $pmDetails = 'Saved Card';
                } else if ($methodLower === 'stripe') {
                    $pmBrand = 'Stripe';
                    $pmDetails = 'Stripe Payment';
                } else if ($methodLower === 'wallet') {
                    $pmBrand = 'Wallet';
                    $pmDetails = 'Wallet Balance';
                } else if ($methodLower === 'cashapp') {
                    $pmBrand = 'CashApp';
                    $pmDetails = 'CashApp Pay';
                } else if ($methodLower === 'voucher') {
                    $pmBrand = 'Voucher';
                    $pmDetails = 'Store Voucher';
                } else if ($methodLower === 'giftcard') {
                    $pmBrand = 'Gift Card';
                    $pmDetails = 'Gift Card';
                } else if ($methodLower === 'financing') {
                    $pmBrand = 'Financing';
                    $pmDetails = 'Installments';
                }

                foreach ($donationsList as $donItem) {
                    $charityName = $donItem['charity_name'];
                    $charity = \App\Models\Charity::where('name', $charityName)->first();
                    $charityCategory = $donItem['charity_category'] ?? ($charity ? $charity->category : 'Charity');
                    $charityLogo = $donItem['charity_logo_type'] ?? ($charity ? $charity->logo_svg_type : 'generic_charity');

                    \App\Models\Donation::create([
                        'order_id' => $order->order_number,
                        'txn_id' => 'TXN-' . rand(10000000, 99999999),
                        'donor_name' => $donorName,
                        'donor_email' => $donorEmail,
                        'charity_name' => $charityName,
                        'charity_category' => $charityCategory,
                        'charity_logo_type' => $charityLogo,
                        'amount' => $donItem['amount'],
                        'note' => $donItem['note'] ?? null,
                        'payment_method_brand' => $pmBrand,
                        'payment_method_details' => $pmDetails,
                        'payment_method_email' => $user ? $user->email : null,
                        'status' => 'Completed',
                    ]);
                }
            }

            // Sync/Deduct Loyalty points in Central Auth API and create local transaction logs
            if ($user) {
                // Deduct redeemed points from Central Auth API
                if ($pointsRedeemed > 0) {
                    \App\Services\LoyaltyService::adjustPoints(
                        $user->id,
                        $pointsRedeemed,
                        'redeemed',
                        "Redeemed points on order {$order->order_number}",
                        $order->id,
                        'redeemed'
                    );
                }

                // Points earned
                if ($pointsEarned > 0) {
                    $isPaid = in_array(strtolower((string)($orderData['payment_status'] ?? '')), ['paid', 'completed']);
                    if ($isPaid) {
                        \App\Services\LoyaltyService::adjustPoints(
                            $user->id,
                            $pointsEarned,
                            'order_completed',
                            "Order Completed #{$order->order_number}",
                            $order->id,
                            'available'
                        );
                    } else {
                        \App\Models\EcommerceLoyaltyTransaction::create([
                            'user_id' => $user->id,
                            'order_id' => $order->id,
                            'transaction_type' => 'earned',
                            'points' => $pointsEarned,
                            'dollar_value' => $pointsEarned * $ratio,
                            'status' => 'pending',
                            'reason' => "Points pending for order {$order->order_number}",
                        ]);
                    }
                }
            }

            $order->statusEvents()->create([
                'user_id' => $user?->id,
                'status' => 'pending',
                'label' => 'Order placed',
            ]);

            // Deduct gift card balances and create ledger transactions
            if (!empty($giftCardsToRedeem)) {
                $remainingToDeduct = $originalTotalAmount;
                $actualApplied = 0.00;

                foreach ($giftCardsToRedeem as $giftCard) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }

                    $oldBalance = (float) $giftCard->current_balance;
                    $deducted = min($remainingToDeduct, $oldBalance);
                    $newBalance = $oldBalance - $deducted;
                    $remainingToDeduct -= $deducted;
                    $actualApplied += $deducted;

                    $newStatus = $newBalance <= 0 ? 'fully used' : 'partially used';
                    $giftCard->update([
                        'current_balance' => $newBalance,
                        'status' => $newStatus,
                        'last_used_at' => now(),
                    ]);

                    // Ledger transaction for Redemption
                    $giftCard->transactions()->create([
                        'transaction_type' => 'Redemption',
                        'amount' => -$deducted,
                        'order_id' => $order->id,
                        'notes' => "Redeemed {$deducted} for order {$order->order_number}.",
                        'created_by' => $user?->id,
                    ]);

                    // Activity log
                    $giftCard->activityLogs()->create([
                        'action' => 'Redeemed',
                        'user_id' => $user?->id,
                        'old_value' => (string) $oldBalance,
                        'new_value' => (string) $newBalance,
                    ]);
                }

                // If fully paid by gift cards, mark order as paid
                if ($remainingToDeduct <= 0) {
                    $order->update([
                        'payment_status' => 'paid',
                    ]);
                }

                // Store gift card info in metadata
                $meta = $order->metadata ?? [];
                $meta['gift_cards_applied'] = collect($giftCardsToRedeem)->map(fn($gc) => $gc->code)->toArray();
                $meta['gift_card_deduction'] = $actualApplied;
                $order->update(['metadata' => $meta]);
            }

            if ($cartItems->isNotEmpty()) {
                foreach ($cartItems as $cartItem) {
                    EcommerceOrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $cartItem->product_id,
                        'product_name' => $cartItem->product?->name ?? 'Product',
                        'product_sku' => $cartItem->product?->sku,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'total_price' => $cartItem->total_price,
                        'product_options' => $cartItem->options ?? [],
                    ]);
                }

                $cart->items()->delete();
                $cart->forceFill(['status' => 'converted', 'total_amount' => 0])->save();
            } else {
                $requestedPids = $requestItems->pluck('product_id')->filter()->map(fn($id) => (int)$id)->all();
                $validProductIds = !empty($requestedPids) ? \App\Models\Product::whereIn('id', $requestedPids)->pluck('id')->all() : [];

                foreach ($requestItems as $item) {
                    $pid = isset($item['product_id']) && is_numeric($item['product_id']) ? (int) $item['product_id'] : null;
                    EcommerceOrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => ($pid && in_array($pid, $validProductIds)) ? $pid : null,
                        'product_name' => $item['product_name'] ?? $item['name'] ?? 'Product',
                        'product_sku' => $item['product_sku'] ?? $item['sku'] ?? null,
                        'quantity' => (int) ($item['quantity'] ?? 1),
                        'unit_price' => (float) ($item['unit_price'] ?? $item['price'] ?? 0),
                        'total_price' => (float) ($item['total_price'] ?? (($item['unit_price'] ?? $item['price'] ?? 0) * ($item['quantity'] ?? 1))),
                        'product_options' => $item['product_options'] ?? $item['options'] ?? [],
                    ]);
                }
            }

            // If paying with Wallet, verify balance and adjust centrally
            if (strtolower($validated['payment_method'] ?? '') === 'wallet') {
                if (!$user) {
                    throw new \Exception('Authentication required to pay with wallet. Please log in to your account or choose another payment method.');
                }

                // If there's an outstanding balance and it wasn't fully paid by gift cards
                if ($order->total_amount > 0 && $order->payment_status !== 'paid') {
                    $token = $request->header('X-Central-Auth-Token') ?? $request->bearerToken();
                    $walletBalance = \App\Services\WalletService::getWalletBalance($user, $token);

                    if ($walletBalance < $order->total_amount) {
                        throw new \Exception("Insufficient wallet balance. You need $" . number_format($order->total_amount, 2) . " but only have $" . number_format($walletBalance, 2) . ".");
                    }

                    $debitSuccess = \App\Services\WalletService::adjustWallet(
                        $user->id,
                        $order->total_amount,
                        'debit',
                        "Payment for order #{$order->order_number}",
                        $order->order_number
                    );

                    if (!$debitSuccess) {
                        throw new \Exception('Failed to deduct payment from your wallet. Please try again.');
                    }

                    $order->update([
                        'payment_status' => 'paid',
                        'status' => 'confirmed',
                    ]);
                }
            }

            DB::commit();

            if ($order->user_id && $order->customer_email) {
                $customerEmail = strtolower(trim((string) $order->customer_email));
                if ($customerEmail !== '') {
                    EcommerceOrder::whereNull('user_id')
                        ->whereRaw('LOWER(customer_email) = ?', [$customerEmail])
                        ->update(['user_id' => $order->user_id]);
                }
            }

            try {
                $emailService = app(EmailNotificationService::class);
                $emailService->sendOrderEvent('order_placed', $order->fresh('items.product'));
                $emailService->sendOrderEvent('new_order', $order->fresh('items.product'));

                if ($pointsRedeemed > 0 && $order->customer_email) {
                    $emailService->sendEvent('loyalty_point_redemption', [
                        'customer_name' => $order->customer_name ?: 'Customer',
                        'customer_email' => $order->customer_email,
                        'points_redeemed' => $pointsRedeemed,
                        'reward_description' => "Redeemed {$pointsRedeemed} points on Order #{$order->order_number}",
                        'site_name' => config('app.name', 'Mecarvi Embroidery'),
                    ], $order->customer_email);
                }
            } catch (\Throwable $e) {
                \Log::warning('Email notification failed: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully.',
                'order' => $order->load('items.product'),
                'data' => $order->load('items.product'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Checkout processing failed: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to process checkout: ' . $e->getMessage()
            ], 500);
        }
    }

    private function addressForUser(?int $userId, mixed $addressId): ?EcommerceAddress
    {
        if (! $userId || ! is_numeric($addressId)) {
            return null;
        }

        return EcommerceAddress::where('user_id', $userId)->whereKey((int) $addressId)->first();
    }

    private function bestAutoCoupon(float $itemsSubtotal, array $context): ?EcommerceCoupon
    {
        return EcommerceCoupon::query()
            ->with('products:id')
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
            ->get()
            ->filter(fn (EcommerceCoupon $coupon) => ($coupon->metadata['apply_method'] ?? 'code') === 'auto')
            ->filter(fn (EcommerceCoupon $coupon) => $coupon->isUsableFor($itemsSubtotal, $context))
            ->sortByDesc(fn (EcommerceCoupon $coupon) => $coupon->discount_type === 'free_shipping'
                ? 0
                : $coupon->discountFor($itemsSubtotal, $context))
            ->first();
    }

    private function activeCentralMemberships(Request $request): array
    {
        $memberships = [];
        $token = $request->header('X-Central-Auth-Token') ?? $request->bearerToken();
        if ($token) {
            try {
                $centralUrl = rtrim(config('services.central_auth.url'), '/');
                $response = Http::acceptJson()
                    ->withToken($token)
                    ->timeout(5)
                    ->get($centralUrl . '/user/memberships');

                if ($response->successful() && is_array($response->json('data'))) {
                    $memberships = $response->json('data');
                }
            } catch (\Throwable $e) {
                Log::warning('Checkout failed to fetch central memberships: ' . $e->getMessage());
            }
        }

        if ($request->user()) {
            $local = \App\Models\EcommerceMembership::where('user_id', $request->user()->id)
                ->whereIn('status', ['Active', 'active', 'trialing', 'pending_cancellation'])
                ->get();
            if ($local->count() > 0) {
                $localMapped = $local->map(function ($m) {
                    $arr = $m->toArray();
                    $plan = \App\Models\EcommerceSubscriptionPlan::where('name', $m->plan_name)
                        ->orWhere('internal_code', $m->plan_name)
                        ->first();
                    if ($plan) {
                        if (empty($arr['benefits_snapshot']) && empty($arr['benefits'])) {
                            $arr['benefits_snapshot'] = $plan->benefit_config ?: $plan->features;
                        }
                        $arr['coverage_type'] = $arr['coverage_type'] ?? $plan->coverage_type;
                        $arr['applicable_site'] = $arr['applicable_site'] ?? $plan->applicable_site;
                        $arr['covered_sites'] = $arr['covered_sites'] ?? $plan->covered_sites;
                    }
                    return $arr;
                })->toArray();

                $memberships = array_merge($memberships, $localMapped);
            }
        }

        return $memberships;
    }

    private function saveAddressForUserIfMissing(User $user, array|EcommerceAddress|null $addressData): void
    {
        try {
            if (!$addressData) {
                return;
            }

            $arr = is_array($addressData) ? $addressData : $addressData->toArray();
            $street = $arr['address'] ?? $arr['address_line_1'] ?? $arr['address_line1'] ?? '';
            $zip = $arr['zip_code'] ?? $arr['zip'] ?? $arr['postal_code'] ?? '';
            $city = $arr['city'] ?? '';

            if (empty($street) || empty($city)) {
                return;
            }

            $exists = $user->addresses()
                ->where(function ($q) use ($street) {
                    $q->where('address', $street)->orWhere('address_line_1', $street);
                })
                ->where('city', $city)
                ->exists();

            if (!$exists) {
                $hasDefault = $user->addresses()->where('is_default', true)->exists();
                $firstName = $arr['first_name'] ?? '';
                $lastName = $arr['last_name'] ?? '';
                if (empty($firstName) && empty($lastName) && !empty($arr['name'])) {
                    $parts = preg_split('/\s+/', trim($arr['name']), 2) ?: [];
                    $firstName = $parts[0] ?? '';
                    $lastName = $parts[1] ?? '';
                }

                $user->addresses()->create([
                    'type' => 'shipping',
                    'first_name' => $firstName ?: $user->name,
                    'last_name' => $lastName ?: '',
                    'company' => $arr['company'] ?? null,
                    'email' => $arr['email'] ?? $user->email,
                    'phone' => $arr['phone'] ?? $user->phone ?? 'N/A',
                    'address' => $street,
                    'address_line_1' => $street,
                    'address_line_2' => $arr['address_line_2'] ?? null,
                    'city' => $city,
                    'state' => $arr['state'] ?? '',
                    'zip_code' => $zip,
                    'postal_code' => $zip,
                    'country' => $arr['country'] ?? 'United States',
                    'is_default' => !$hasDefault,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Auto-saving address during checkout failed: ' . $e->getMessage());
        }
    }

    /**
     * Preview calculated tax for a given address state, subtotal, and shipping amount.
     */
    public function calculateTaxPreview(Request $request)
    {
        $validated = $request->validate([
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'subtotal' => 'nullable|numeric',
            'shipping_amount' => 'nullable|numeric',
            'discount_amount' => 'nullable|numeric',
            'is_tax_exempt' => 'nullable|boolean',
        ]);

        $user = auth('sanctum')->user() ?: $request->user();

        $calc = app(\App\Services\TaxCalculationService::class)->calculate([
            'state' => $validated['state'] ?? null,
            'country' => $validated['country'] ?? 'US',
            'subtotal' => (float)($validated['subtotal'] ?? 0),
            'shipping_amount' => (float)($validated['shipping_amount'] ?? 0),
            'discount_amount' => (float)($validated['discount_amount'] ?? 0),
            'is_tax_exempt' => !empty($validated['is_tax_exempt']),
            'user' => $user,
        ]);

        return response()->json([
            'success' => true,
            'data' => $calc,
        ]);
    }
}

