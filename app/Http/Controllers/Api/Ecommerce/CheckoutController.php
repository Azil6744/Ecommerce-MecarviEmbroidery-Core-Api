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
use App\Services\DealPricingService;
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
            // Partial wallet payment: wallet covers wallet_amount, wallet_secondary_method covers the rest.
            'wallet_amount' => 'nullable|numeric|min:0',
            'wallet_secondary_method' => 'nullable|string|in:card,stripe,saved_card,paypal,cashapp,financing',
            'shipping_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'coupon_code' => 'nullable|string|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'tip_amount' => 'nullable|numeric|min:0',
            'donation_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'turnaround_time' => 'nullable|string|max:100',
            'turnaround_time_id' => 'nullable',
            'turnaround_amount' => 'nullable|numeric|min:0',
            'packaging_option' => 'nullable|string|max:255',
            'packaging_mode' => 'nullable|string|max:50',
            'packaging_style' => 'nullable|string|max:100',
            'packages' => 'nullable|array',
            'additional_packaging_options' => 'nullable|array',
            'gift_message' => 'nullable|string',
            'gift_card_codes' => 'nullable|array',
            'gift_card_codes.*' => 'string',
            'voucher_codes' => 'nullable|array',
            'voucher_codes.*' => 'string',
            'redemption_channel' => 'nullable|in:online,pos',
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
            'shipping_type' => 'nullable|string|max:20',
            'shipping_rate_token' => 'nullable|string',
            'shipping_groups' => 'nullable|array',
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
            $loyaltySettings = \App\Services\LoyaltyService::getSettings();
            $ratio = (float) $loyaltySettings['points_to_dollar_ratio'];
            $loyaltyEnabled = (bool) $loyaltySettings['enabled'];

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

            // Points belong to a signed-in account. A guest checkout may be matched to an account by
            // the email typed in (to attach the order), but must never spend that account's points.
            $pointsOwner = $request->user();
            if (! $pointsOwner && ((int) $request->input('points_redeemed', 0) > 0 || ! empty($request->input('pay_with_points_item_ids')))) {
                return response()->json(['success' => false, 'message' => 'Please sign in to use loyalty points.'], 401);
            }

            // Calculate points needed for pay_with_points items
            if ($pointsOwner && !empty($validated['pay_with_points_item_ids'])) {
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
            if ($pointsOwner && !empty($validated['points_redeemed'])) {
                $generalPointsRedeemed = (int)$validated['points_redeemed'];
                $pointsRedeemed += $generalPointsRedeemed;
            }

            // Validation moved below where variables are resolved

            $shippingAmount = round((float) ($validated['shipping_amount'] ?? 0), 2);
            $rawShippingAmount = $shippingAmount; // before coupon shipping discounts
            // The discount is always calculated on the server. A client-sent discount_amount is ignored,
            // otherwise any customer could send an arbitrary discount.
            $couponDiscount = 0.0;

            $itemsSubtotal = $cartItems->isNotEmpty()
                ? (float) $cartItems->sum('total_price')
                : (float) $requestItems->sum(fn ($item) => (float) ($item['total_price'] ?? (($item['unit_price'] ?? $item['price'] ?? 0) * ($item['quantity'] ?? 1))));

            $productIds = $cartItems->isNotEmpty()
                ? $cartItems->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->values()->all()
                : $requestItems->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
            $couponContext = [
                'product_ids' => $productIds,
                'lines' => $this->pricingLines($cartItems, $requestItems),
                'user_id' => $user?->id,
                'customer_email' => $user?->email ?? $request->input('customer_email'),
                'customer_type' => strtolower((string) $user?->role) === 'business' ? 'business' : 'personal',
            ];

            // Deals (bundle prices) are priced from the cart lines, separately from coupons.
            $pricingLines = $this->pricingLines($cartItems, $requestItems);
            $dealService = app(DealPricingService::class);
            $appliedDeal = null;
            $dealBundles = 0;

            $appliedCoupon = null;
            $couponCode = strtoupper(trim((string) ($validated['coupon_code'] ?? '')));
            if ($couponCode !== '') {
                $coupon = EcommerceCoupon::query()
                    ->with('products:id')
                    ->whereRaw('UPPER(code) = ?', [$couponCode])
                    ->first();

                if ($coupon && $coupon->isDeal()) {
                    $dealResult = $dealService->evaluate($coupon, $pricingLines, $couponContext);
                    if ($dealResult['discount'] <= 0) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This deal does not apply to your cart.',
                        ], 422);
                    }
                    $appliedDeal = $coupon;
                    $dealBundles = $dealResult['bundles'];
                } elseif (! $coupon || ! $coupon->isUsableFor($itemsSubtotal, $couponContext)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Coupon is not valid for this order.',
                    ], 422);
                } else {
                    $appliedCoupon = $coupon;
                    $couponDiscount = max($couponDiscount, $coupon->discountFor($itemsSubtotal, $couponContext));
                }
            } else {
                $appliedCoupon = $this->bestAutoCoupon($itemsSubtotal, $couponContext);
                if ($appliedCoupon) {
                    $couponCode = $appliedCoupon->code;
                    $couponDiscount = max($couponDiscount, $appliedCoupon->discountFor($itemsSubtotal, $couponContext));
                }
            }

            // Automatic deals: the customer gets the best deal when it beats the coupon discount.
            if ($appliedDeal === null) {
                $bestDeal = $dealService->best($pricingLines, $couponContext);
                if ($bestDeal['deal'] && $bestDeal['discount'] > $couponDiscount) {
                    $appliedDeal = $bestDeal['deal'];
                    $dealBundles = $bestDeal['bundles'];
                    $appliedCoupon = null;
                    $couponDiscount = 0.0;
                    $couponCode = '';
                }
            }

            if ($appliedDeal) {
                $couponDiscount = $dealService->evaluate($appliedDeal, $pricingLines, $couponContext)['discount'];
                $couponCode = $appliedDeal->code;
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
                $appliedCoupon !== null,
                $request->user()?->id
            );

            if ($membershipBenefits['membership_discount_amount'] > 0) {
                $totalDiscount = round($totalDiscount + $membershipBenefits['membership_discount_amount'], 2);
            }

            // "Priority Order Processing" in the member's plan: recorded on the order so staff can
            // see it in the order's membership benefits and handle it first.
            $memberPerks = MembershipBenefitService::perksFor($user);
            if ($memberPerks['priority_processing']) {
                $membershipBenefits['membership_benefit_usage'][] = [
                    'label' => 'Priority Order Processing',
                    'type' => 'priority_processing',
                    'amount' => 0,
                ];
                $membershipBenefits['membership_plan_name'] ??= $memberPerks['plan'];
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

            // Carrier shipping: the price must match a live rate issued by the carrier APIs for this address
            // (never trust the browser price). Local delivery and pickup do not use carrier rates.
            if (($validated['shipping_type'] ?? null) === 'shipping') {
                $addr = is_array($shippingAddress) ? $shippingAddress : (is_object($shippingAddress) ? $shippingAddress->toArray() : []);
                $destZip = trim((string) ($addr['zip'] ?? $addr['zip_code'] ?? $addr['postal_code'] ?? ''));
                $destCountry = (string) ($addr['country'] ?? 'United States');
                $groups = is_array($validated['shipping_groups'] ?? null) ? $validated['shipping_groups'] : [];
                $tokenPrice = null;

                if (count($groups) > 1 && collect($groups)->every(fn ($g) => !empty($g['rate_token']))) {
                    $sum = 0.0;
                    $allValid = true;
                    foreach ($groups as $g) {
                        $gZip = trim((string) ($g['zip'] ?? $destZip));
                        $gCountry = (string) ($g['country'] ?? $destCountry);
                        $vr = \App\Services\Shipping\ShippingRateService::verifyRateToken((string) $g['rate_token'], $gZip, $gCountry);
                        if (!$vr) {
                            $allValid = false;
                            break;
                        }
                        $sum += (float) $vr['price'];
                    }
                    if ($allValid) {
                        $tokenPrice = round($sum, 2);
                    }
                } else {
                    $verifiedRate = \App\Services\Shipping\ShippingRateService::verifyRateToken((string) ($validated['shipping_rate_token'] ?? ''), $destZip, $destCountry);
                    $tokenPrice = $verifiedRate ? (float) $verifiedRate['price'] : null;
                }

                // The only shipping amounts accepted are the verified quote, or the quote less the
                // membership's own shipping benefit (worked out here, not taken from the browser).
                $memberships = $this->activeCentralMemberships($request);
                $allowedShipping = [];
                if ($tokenPrice !== null) {
                    $allowedShipping[] = round($tokenPrice, 2);
                    if (collect($memberships)->isNotEmpty()) {
                        $shippingBenefit = (float) app(MembershipBenefitService::class)->evaluate(
                            $memberships,
                            0.0,
                            $tokenPrice,
                            config('services.mccarvy_site.slug', 'embroidery'),
                            $appliedCoupon !== null,
                            $request->user()?->id
                        )['membership_discount_amount'];
                        $allowedShipping[] = round(max(0, $tokenPrice - min($tokenPrice, $shippingBenefit)), 2);
                    }
                }
                $priceOk = $tokenPrice !== null && collect(array_unique($allowedShipping))
                    ->contains(fn ($allowed) => abs($rawShippingAmount - $allowed) < 0.01);
                if (!$priceOk) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Shipping is not available for this address, or the shipping rate has expired. Please re-select a shipping option.',
                    ], 422);
                }
            } elseif (($validated['shipping_type'] ?? null) === 'delivery') {
                $deliveryCfg = $settings && $settings->delivery_settings ? json_decode($settings->delivery_settings, true) : [];
                $maxRadius = (float) ($deliveryCfg['max_delivery_radius'] ?? 10);
                $groups = is_array($validated['shipping_groups'] ?? null) ? $validated['shipping_groups'] : [];
                if (empty($groups)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Local delivery requires a verified address within {$maxRadius} miles of a store. Please choose Shipping or Store Pick Up.",
                    ], 422);
                }
                foreach ($groups as $g) {
                    $carrier = strtolower((string) ($g['carrier'] ?? ''));
                    // Skip store pickup or commercial carrier shipping packages in multi-package fulfillment
                    if (in_array($carrier, ['store_pickup', 'pickup', 'usps', 'ups', 'fedex', 'dhl', 'easypost', 'shippo'], true)) {
                        continue;
                    }
                    if (!isset($g['distance_miles']) || !is_numeric($g['distance_miles']) || (float) $g['distance_miles'] > $maxRadius) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Local delivery is only available within {$maxRadius} miles of a store. Please choose Shipping or Store Pick Up.",
                        ], 422);
                    }
                }
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
            $turnaroundAmount = round((float) ($validated['turnaround_amount'] ?? 0), 2);

            $charityEnabled = $settings ? (bool)$settings->charity_donation_enabled : true;
            if ($donationAmount > 0 && !$charityEnabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Charity donations are currently disabled.',
                ], 400);
            }

            // Subtotal + shipping + packaging + turnaround + tax + tip + donation - totalDiscount
            $originalTotalAmount = round((float) ($itemsSubtotal + $shippingAmount + $taxAmount + $tipAmount + $donationAmount + $packagingAmount + $turnaroundAmount - $totalDiscount), 2);
            $totalAmount = max(0.00, $originalTotalAmount);

            // Gift card validation
            $giftCardCodes = $validated['gift_card_codes'] ?? [];

            // If user is redeeming points, validate through the shared loyalty engine rules
            if ($pointsRedeemed > 0) {
                if (! $user) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Please sign in to use loyalty points.'], 401);
                }

                if (! $loyaltyEnabled) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Loyalty rewards program is currently disabled.'], 400);
                }

                if ($generalPointsRedeemed > 0) {
                    $loyaltyCheck = \App\Services\LoyaltyService::validateRedemption($user, $generalPointsRedeemed, [
                        'subtotal' => $itemsSubtotal,
                        'coupon_discount' => $couponDiscount,
                        'other_discount' => (float) ($membershipBenefits['membership_discount_amount'] ?? 0),
                        'has_coupon' => ! empty($couponCode),
                        'has_gift_card' => ! empty($giftCardCodes),
                    ]);
                    if (! $loyaltyCheck['ok']) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => $loyaltyCheck['message']], 400);
                    }
                }

                $pointsBalance = \App\Services\LoyaltyService::getOrCreateBalance($user);
                if ($pointsBalance->is_locked) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Your loyalty points account is currently suspended: ' . ($pointsBalance->locked_reason ?: 'Please contact support.'),
                    ], 400);
                }
                if ((int) $pointsBalance->available_points < $pointsRedeemed) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient loyalty points. You need {$pointsRedeemed} points but only have " . max(0, (int) $pointsBalance->available_points) . '.',
                    ], 400);
                }
            }
            $giftCardsToRedeem = [];
            $totalGiftCardApplied = 0.00;

            $giftCardPlan = [];

            if (!empty($giftCardCodes)) {
                foreach (array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $giftCardCodes))) as $code) {
                    $giftCard = EcommerceGiftCard::whereRaw('UPPER(code) = ?', [$code])->first();

                    if (!$giftCard) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Invalid gift card code: {$code}"
                        ], 400);
                    }

                    $giftCardsToRedeem[] = $giftCard;
                }

                // Status, expiry, balance and the Gift Card Settings limits (minimum
                // balance, max per redemption, one-card rule, partial redemption).
                try {
                    $planned = \App\Services\GiftCardRedemptionService::plan($giftCardsToRedeem, $totalAmount);
                } catch (\RuntimeException $planError) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => $planError->getMessage()], 400);
                }
                $giftCardPlan = $planned['plan'];
                $totalGiftCardApplied = $planned['total'];

                // Deduct from total amount
                $totalAmount = max(0.00, round($totalAmount - $totalGiftCardApplied, 2));
            }

            $voucherCodes = $validated['voucher_codes'] ?? [];
            $voucherResult = \App\Services\VoucherService::validateForOrder(
                $voucherCodes,
                $totalAmount,
                $validated['redemption_channel'] ?? 'online'
            );
            if (! $voucherResult['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $voucherResult['message'],
                ], 400);
            }
            $totalVoucherApplied = (float) $voucherResult['total_applied'];
            if ($totalVoucherApplied > 0) {
                $totalAmount = max(0.00, round($totalAmount - $totalVoucherApplied, 2));
            }


            // 3. Estimated loyalty points. The binding award is created by
            //    LoyaltyService::awardPendingOrderPoints() once the order exists.
            $pointsEarned = 0;
            if ($user && $loyaltyEnabled) {
                $earnLines = $cartItems->isNotEmpty()
                    ? $cartItems->map(fn ($ci) => ['product_id' => $ci->product_id, 'total_price' => $ci->total_price])
                    : $requestItems->map(fn ($ri) => ['product_id' => $ri['product_id'] ?? null, 'total_price' => (float) ($ri['total_price'] ?? (($ri['unit_price'] ?? $ri['price'] ?? 0) * ($ri['quantity'] ?? 1)))]);
                $earnEstimate = \App\Services\LoyaltyService::calculateEarning($user, [
                    'subtotal' => $itemsSubtotal,
                    'discount' => $totalDiscount,
                    'coupon_discount' => $couponDiscount,
                    'shipping' => $shippingAmount,
                    'tax' => $taxAmount,
                    'gift_card' => $totalGiftCardApplied,
                    'voucher' => $totalVoucherApplied,
                    'excluded_amount' => \App\Services\LoyaltyService::excludedAmount($earnLines),
                ]);
                $pointsEarned = (int) $earnEstimate['total'];
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
                    'turnaround_time' => $validated['turnaround_time'] ?? null,
                    'turnaround_time_id' => $validated['turnaround_time_id'] ?? null,
                    'turnaround_amount' => $turnaroundAmount ?? 0,
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
                    'coupon_code' => $appliedCoupon?->code ?? $appliedDeal?->code,
                    'coupon' => $appliedCoupon?->toManagementArray(),
                    'deal_code' => $appliedDeal?->code,
                    'deal' => $appliedDeal ? array_merge($appliedDeal->toManagementArray(), ['bundles_applied' => $dealBundles]) : null,
                    'membership_benefits' => $membershipBenefits,
                    'tax_rate' => $taxRate,
                    'tax_breakdown' => $taxCalculation,
                    'gift_card_applied_amount' => $totalGiftCardApplied,
                    'gift_card_codes' => $giftCardCodes,
                    'checkout_payload' => $validated,
                ],
                'order_number' => EcommerceOrder::generateOrderNumber(),
                'order_date' => Carbon::today(),
            ];

            // Lock the coupon/deal row and re-check its limits, so two checkouts at the same time
            // cannot both use the last remaining use.
            foreach (array_filter([$appliedCoupon, $appliedDeal]) as $promotion) {
                $locked = EcommerceCoupon::query()->whereKey($promotion->id)->lockForUpdate()->first();
                if (! $locked || ($locked->usage_limit !== null && (int) $locked->used_count >= (int) $locked->usage_limit)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "The offer {$promotion->code} has just reached its usage limit. Please remove it and try again.",
                    ], 422);
                }
            }

            $order = EcommerceOrder::create($orderData);

            if ($appliedCoupon) {
                $appliedCoupon->increment('used_count');
            }
            if ($appliedDeal) {
                $appliedDeal->increment('used_count');
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
                            'charity_id' => $item['charity_id'] ?? ($item['id'] ?? null),
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
                    $charityName = trim((string) $donItem['charity_name']);
                    $charity = (!empty($donItem['charity_id']) && is_numeric($donItem['charity_id'])) 
                        ? \App\Models\Charity::find((int) $donItem['charity_id']) 
                        : null;
                    if (!$charity && $charityName !== '') {
                        $charity = \App\Models\Charity::whereRaw('LOWER(name) = ?', [mb_strtolower($charityName)])->first()
                            ?? \App\Models\Charity::where('name', 'like', $charityName . '%')->first();
                    }
                    if ($charity) {
                        $charityName = $charity->name;
                    }
                    $charityCategory = $donItem['charity_category'] ?? ($charity ? $charity->category : 'Charity');
                    $charityLogo = $donItem['charity_logo_type'] ?? ($charity ? $charity->logo_svg_type : 'generic_charity');

                    \App\Models\Donation::create([
                        'user_id' => $user?->id,
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

            // Split the single gateway charge into ledger transactions (order, shipping per address,
            // turnaround, tip, each donation) so the admin can see and reconcile them separately.
            try {
                app(\App\Services\OrderTransactionLedger::class)->record($order, [
                    'shipping' => $shippingAmount,
                    'shipping_groups' => $validated['shipping_groups'] ?? [],
                    'packaging' => $packagingAmount,
                    'turnaround' => $turnaroundAmount,
                    'tip' => $tipAmount,
                    'donations' => $donationsList ?? [],
                ]);
            } catch (\Throwable $ledgerError) {
                \Log::error('Order transaction ledger failed: ' . $ledgerError->getMessage(), ['order' => $order->order_number]);
            }

            $order->statusEvents()->create([
                'user_id' => $user?->id,
                'status' => 'pending',
                'label' => 'Order placed',
            ]);

            // Deduct gift card balances and create ledger transactions. Each card is
            // re-read under a row lock; if its balance changed since it was planned
            // (another checkout used it), this throws and the order rolls back.
            if (!empty($giftCardPlan)) {
                $actualApplied = \App\Services\GiftCardRedemptionService::redeem($giftCardPlan, $order, $user?->id);
                $remainingToDeduct = round($originalTotalAmount - $actualApplied, 2);

                // If fully paid by gift cards, mark order as paid
                if ($remainingToDeduct <= 0) {
                    $order->update([
                        'payment_status' => 'paid',
                    ]);
                }

                // Store gift card info in metadata
                $meta = $order->metadata ?? [];
                $meta['gift_cards_applied'] = collect($giftCardsToRedeem)->filter(fn ($gc) => isset($giftCardPlan[$gc->id]))->map(fn ($gc) => $gc->code)->values()->toArray();
                $meta['gift_card_deduction'] = $actualApplied;
                $order->update(['metadata' => $meta]);
            }

            if (! empty($voucherResult['vouchers'])) {
                \App\Services\VoucherService::redeemForOrder($order, $voucherResult['vouchers'], $user?->id);
                if ($totalAmount <= 0) {
                    $order->update(['payment_status' => 'paid']);
                }
            }

            $deductProductStock = function (\App\Models\Product $product, int $qty) {
                $isDigital = (bool) $product->is_digital || in_array(strtolower((string) $product->product_type), ['digital', 'quotation'], true);
                if ($isDigital || $product->stock_quantity === null || $qty <= 0) {
                    return;
                }
                $available = (int) $product->stock_quantity;
                if ($available < $qty) {
                    throw new \RuntimeException(
                        $available <= 0
                            ? "Sorry, \"{$product->name}\" is currently out of stock."
                            : "Insufficient stock for \"{$product->name}\". Only {$available} left in stock."
                    );
                }
                $newStock = max(0, $available - $qty);
                $attrs = is_array($product->attributes) ? $product->attributes : [];
                if (array_key_exists('stockQuantity', $attrs)) {
                    $attrs['stockQuantity'] = (string) $newStock;
                }
                $product->forceFill([
                    'stock_quantity' => $newStock,
                    'attributes' => $attrs,
                ])->save();
            };

            if ($cartItems->isNotEmpty()) {
                foreach ($cartItems as $cartItem) {
                    $prod = $cartItem->product_id
                        ? \App\Models\Product::whereKey($cartItem->product_id)->lockForUpdate()->first()
                        : null;
                    $qty = max(1, (int) $cartItem->quantity);
                    if ($prod) {
                        $deductProductStock($prod, $qty);
                    }

                    EcommerceOrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $prod?->id ?? $cartItem->product_id,
                        'product_name' => $prod?->name ?? $cartItem->product?->name ?? 'Product',
                        'product_sku' => $prod?->sku ?? $cartItem->product?->sku,
                        'quantity' => $qty,
                        'unit_price' => $cartItem->unit_price,
                        'total_price' => $cartItem->total_price,
                        'product_options' => $cartItem->options ?? [],
                    ]);
                }

                $cart->items()->delete();
                $cart->forceFill(['status' => 'converted', 'total_amount' => 0])->save();
            } else {
                foreach ($requestItems as $item) {
                    $pid = isset($item['product_id']) && is_numeric($item['product_id']) ? (int) $item['product_id'] : null;
                    $sku = trim((string) ($item['product_sku'] ?? $item['sku'] ?? ''));
                    $name = trim((string) ($item['product_name'] ?? $item['name'] ?? ''));

                    $prod = null;
                    if ($pid) {
                        $prod = \App\Models\Product::whereKey($pid)->lockForUpdate()->first();
                    }
                    if (!$prod && $sku !== '') {
                        $prod = \App\Models\Product::where('sku', $sku)->lockForUpdate()->first();
                    }
                    if (!$prod && $name !== '') {
                        $prod = \App\Models\Product::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->lockForUpdate()->first();
                    }

                    $qty = max(1, (int) ($item['quantity'] ?? 1));
                    if ($prod) {
                        $deductProductStock($prod, $qty);
                    }

                    EcommerceOrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $prod?->id,
                        'product_name' => $prod?->name ?? ($name !== '' ? $name : 'Product'),
                        'product_sku' => $prod?->sku ?? ($sku !== '' ? $sku : null),
                        'quantity' => $qty,
                        'unit_price' => (float) ($item['unit_price'] ?? $item['price'] ?? 0),
                        'total_price' => (float) ($item['total_price'] ?? (($item['unit_price'] ?? $item['price'] ?? 0) * $qty)),
                        'product_options' => $item['product_options'] ?? $item['options'] ?? [],
                    ]);
                }
            }

            $metaAfterStock = $order->metadata ?? [];
            $metaAfterStock['stock_deducted'] = true;
            $order->update(['metadata' => $metaAfterStock]);

            // If paying with Wallet, verify balance and adjust centrally
            if (strtolower($validated['payment_method'] ?? '') === 'wallet') {
                if (!$user) {
                    throw new \Exception('Authentication required to pay with wallet. Please log in to your account or choose another payment method.');
                }

                // If there's an outstanding balance and it wasn't fully paid by gift cards
                if ($order->total_amount > 0 && $order->payment_status !== 'paid') {
                    $token = $request->header('X-Central-Auth-Token') ?? $request->bearerToken();
                    $walletBalance = \App\Services\WalletService::getWalletBalance($user, $token);

                    $orderTotal = round((float) $order->total_amount, 2);
                    $requestedWallet = $request->filled('wallet_amount') ? round((float) $validated['wallet_amount'], 2) : $orderTotal;
                    $isPartial = $requestedWallet > 0 && $requestedWallet < $orderTotal - 0.009;
                    $walletAmount = $isPartial ? $requestedWallet : $orderTotal;
                    // RuntimeException → rolled back and returned as 422 with this message.
                    if ($walletError = \App\Services\MecarviCashService::validateWalletSpend($user, $walletAmount, $walletBalance, $isPartial)) {
                        throw new \RuntimeException($walletError);
                    }
                    $secondaryMethod = strtolower((string) ($validated['wallet_secondary_method'] ?? ''));
                    if ($isPartial && $secondaryMethod === '') {
                        throw new \RuntimeException('Choose a payment method for the remaining $' . number_format($orderTotal - $walletAmount, 2) . '.');
                    }

                    if ($walletBalance < $walletAmount) {
                        throw new \RuntimeException("Insufficient wallet balance. You need $" . number_format($walletAmount, 2) . " but only have $" . number_format($walletBalance, 2) . ".");
                    }

                    $debitSuccess = \App\Services\WalletService::adjustWallet(
                        $user->id,
                        $walletAmount,
                        'debit',
                        "Payment for order #{$order->order_number}" . ($isPartial ? ' (partial)' : ''),
                        $order->order_number,
                        true,
                        ['category' => 'payment']
                    );

                    if (!$debitSuccess) {
                        throw new \Exception('Failed to deduct payment from your wallet. Please try again.');
                    }

                    $walletMeta = is_array($order->metadata) ? $order->metadata : [];
                    $walletMeta['wallet_amount'] = $walletAmount;
                    if ($isPartial) {
                        $walletMeta['wallet_secondary_method'] = $secondaryMethod;
                        $walletMeta['wallet_secondary_amount'] = round($orderTotal - $walletAmount, 2);
                    }

                    $order->update([
                        'payment_status' => 'paid',
                        'status' => 'confirmed',
                        'metadata' => $walletMeta,
                    ]);
                }
            }

            // Process Loyalty Points Redemption and Pending Award (after order items and payment status are finalized)
            if ($user) {
                // 1. Deduct redeemed points
                if ($pointsRedeemed > 0) {
                    try {
                        \App\Services\LoyaltyService::redeemPoints($user, $pointsRedeemed, $order);
                    } catch (\App\Services\LoyaltyException $loyaltyEx) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => $loyaltyEx->getMessage(),
                        ], 400);
                    }
                }

                // 2. Award pending points on order (activated on order completion/delivery or configured trigger)
                if ($loyaltyEnabled) {
                    \App\Services\LoyaltyService::awardPendingOrderPoints($order);
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

        } catch (\RuntimeException $stockEx) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $stockEx->getMessage(),
            ], 422);
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

    /**
     * POST /api/ecommerce/checkout/deal-preview
     * Shows the deal discount for the cart before checkout. Uses the same engine as order placement.
     * Body: items: [{product_id, unit_price, quantity}]
     */
    public function dealPreview(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            "items" => "required|array|max:100",
            "items.*.product_id" => "required|integer|exists:products,id",
            "items.*.unit_price" => "required|numeric|min:0",
            "items.*.quantity" => "required|integer|min:1|max:10000",
            "items.*.deal_code" => "nullable|string|max:100",
        ]);

        $lines = collect($validated["items"])->map(fn ($item) => [
            "product_id" => (int) $item["product_id"],
            "unit_price" => (float) $item["unit_price"],
            "quantity" => (int) $item["quantity"],
            "deal_code" => $item["deal_code"] ?? null,
        ])->values()->all();

        $context = [
            "product_ids" => collect($lines)->pluck("product_id")->unique()->values()->all(),
            "lines" => $lines,
            "user_id" => $request->user()?->id,
            "customer_email" => $request->user()?->email,
            "customer_type" => strtolower((string) $request->user()?->role) === "business" ? "business" : "personal",
        ];

        $best = app(DealPricingService::class)->best($lines, $context);

        // A typed buy-X-get-Y coupon is priced from the cart lines as well, so the preview matches the order
        $couponDiscount = null;
        $couponCode = strtoupper(trim((string) $request->input("coupon_code", "")));
        if ($couponCode !== "") {
            $coupon = EcommerceCoupon::query()->with("products:id")->whereRaw("UPPER(code) = ?", [$couponCode])->first();
            if ($coupon && ! $coupon->isDeal() && $coupon->discount_type === "buy_x_get_y") {
                $subtotal = round(collect($lines)->sum(fn ($l) => $l["unit_price"] * $l["quantity"]), 2);
                $couponDiscount = $coupon->isUsableFor($subtotal, $context)
                    ? round((float) $coupon->discountFor($subtotal, $context), 2)
                    : 0.0;
            }
        }

        return response()->json([
            "success" => true,
            "data" => [
                "discount" => round((float) $best["discount"], 2),
                "bundles" => (int) $best["bundles"],
                "deal" => $best["deal"] ? [
                    "code" => $best["deal"]->code,
                    "title" => $best["deal"]->title,
                ] : null,
                "coupon_discount" => $couponDiscount,
            ],
        ]);
    }

    /**
     * Cart lines in the shape DealPricingService expects.
     */
    /** Deal code of a cart line added through the deal bundle builder (options.deal_bundle). */
    private function dealCodeOf(array $options): ?string
    {
        $code = $options["deal_bundle"]["deal_code"] ?? null;

        return is_string($code) && $code !== "" ? $code : null;
    }

    private function pricingLines($cartItems, $requestItems): array
    {
        if ($cartItems->isNotEmpty()) {
            return $cartItems->map(fn ($item) => [
                "product_id" => (int) $item->product_id,
                "unit_price" => (float) $item->unit_price,
                "quantity" => (int) $item->quantity,
                "deal_code" => $this->dealCodeOf(is_array($item->options) ? $item->options : []),
            ])->values()->all();
        }

        return $requestItems->map(fn ($item) => [
            "product_id" => (int) ($item["product_id"] ?? 0),
            "unit_price" => (float) ($item["unit_price"] ?? $item["price"] ?? 0),
            "quantity" => (int) ($item["quantity"] ?? 1),
            "deal_code" => $item["deal_code"] ?? $this->dealCodeOf((array) ($item["attributes"] ?? $item["options"] ?? [])),
        ])->values()->all();
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
            ->reject(fn (EcommerceCoupon $coupon) => $coupon->isDeal())
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

        // Team seats: members get the owner's live business membership benefits.
        if ($request->user()) {
            $memberships = array_merge($memberships, \App\Services\MembershipTeamService::sharedMembershipsFor($request->user()));
        }

        if ($request->user()) {
            // Core saves statuses capitalised ("Trialing", "Pending Cancellation"): compare case-insensitively.
            $live = MembershipBenefitService::LIVE_STATUSES;
            $local = \App\Models\EcommerceMembership::where('user_id', $request->user()->id)
                ->whereRaw('LOWER(REPLACE(status, \' \', \'_\')) IN (' . implode(',', array_fill(0, count($live), '?')) . ')', $live)
                ->get();
            if ($local->count() > 0) {
                $localMapped = $local->map(function ($m) {
                    $arr = $m->toArray();
                    // The evaluator expects lower-case statuses.
                    $arr['status'] = strtolower(str_replace(' ', '_', (string) $m->status));
                    $plan = ($m->plan_id ? \App\Models\EcommerceSubscriptionPlan::find($m->plan_id) : null)
                        ?: \App\Models\EcommerceSubscriptionPlan::where('name', $m->plan_name)
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

