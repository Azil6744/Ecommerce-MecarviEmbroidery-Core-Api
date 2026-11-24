<?php

namespace App\Services;

use App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController;
use App\Models\EcommerceGiftCardOrder;
use App\Models\User;

/**
 * What customers may buy, from Admin → Gift Cards Settings: the module switch,
 * which card types are on, preset values, the custom-amount range, the min / max
 * purchase amount and the daily purchase limit. The panels show the same values
 * (publicSettings) and the order endpoint enforces them (check).
 */
class GiftCardPurchaseRules
{
    public static function settings(): array
    {
        return (new EcommerceGiftCardController())->currentSettings();
    }

    /** The subset the storefront / panels need to build the purchase form. */
    public static function publicSettings(): array
    {
        $s = self::settings();
        $type = fn (array $t) => [
            'enabled' => (bool) $t['enabled'],
            'preset_values' => array_values(array_filter(
                $t['preset_values'],
                fn ($v) => $v >= (float) $s['min_purchase_amount'] && ($s['max_purchase_amount'] <= 0 || $v <= (float) $s['max_purchase_amount'])
            )),
            'allow_custom' => (bool) $t['allow_custom'],
            // The custom range never goes outside the overall purchase limits.
            'min_custom' => max((float) $t['min_custom'], (float) $s['min_purchase_amount']),
            'max_custom' => $s['max_purchase_amount'] > 0 ? min((float) $t['max_custom'], (float) $s['max_purchase_amount']) : (float) $t['max_custom'],
        ];

        return [
            'enabled' => (bool) $s['enable_module'],
            'digital' => $type($s['egift']),
            'physical' => $type($s['physical']),
            'min_purchase_amount' => (float) $s['min_purchase_amount'],
            'max_purchase_amount' => (float) $s['max_purchase_amount'],
            'daily_purchase_limit' => (int) $s['daily_purchase_limit'],
            'allow_partial_redemption' => (bool) $s['allow_partial_redemption'],
            'expiry_enabled' => (bool) $s['expiry_enabled'],
            'expiry_months' => (int) $s['expiry_months'],
            'currency' => 'USD',
        ];
    }

    /** Why this purchase is not allowed, or null when it is. */
    public static function check(string $deliveryMethod, float $amount, ?User $user, ?string $buyerEmail = null): ?string
    {
        $s = self::publicSettings();
        if (! $s['enabled']) {
            return 'Gift cards are not available for purchase right now.';
        }

        $kind = $deliveryMethod === 'physical' ? 'physical' : 'digital';
        $type = $s[$kind];
        if (! $type['enabled']) {
            return ($kind === 'physical' ? 'Physical' : 'Digital').' gift cards are not available right now.';
        }

        $amount = round($amount, 2);
        $isPreset = collect($type['preset_values'])->contains(fn ($v) => abs((float) $v - $amount) < 0.001);
        if (! $isPreset) {
            if (! $type['allow_custom']) {
                return 'Please choose one of the available amounts: '.collect($type['preset_values'])->map(fn ($v) => '$'.rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'))->implode(', ').'.';
            }
            if ($amount < $type['min_custom'] || $amount > $type['max_custom']) {
                return sprintf('The gift card amount must be between $%.2f and $%.2f.', $type['min_custom'], $type['max_custom']);
            }
        }

        if ($s['daily_purchase_limit'] > 0 && ($user || $buyerEmail)) {
            $today = EcommerceGiftCardOrder::query()
                ->where(function ($q) use ($user, $buyerEmail) {
                    if ($user) {
                        $q->where('customer_id', $user->id);
                    }
                    if ($buyerEmail) {
                        $q->orWhereRaw('LOWER(buyer_email) = ?', [strtolower($buyerEmail)]);
                    }
                })
                ->where('created_at', '>=', now()->startOfDay())
                // Paid purchases only: an abandoned checkout doesn't use up the day's allowance.
                ->where('payment_status', 'paid')
                ->whereNotIn('order_status', ['Cancelled', 'Declined', 'Refunded'])
                ->count();
            if ($today >= $s['daily_purchase_limit']) {
                return "You can buy up to {$s['daily_purchase_limit']} gift cards per day. Please try again tomorrow.";
            }
        }

        return null;
    }
}
