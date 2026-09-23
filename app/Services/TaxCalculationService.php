<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\TaxRate;
use App\Models\User;

class TaxCalculationService
{
    const US_STATES = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
        'DC' => 'District of Columbia',
    ];

    public static function normalizeState(?string $stateInput): array
    {
        if (empty($stateInput)) {
            return ['code' => null, 'name' => null];
        }

        $trimmed = trim($stateInput);
        $upper = strtoupper($trimmed);

        if (isset(self::US_STATES[$upper])) {
            return [
                'code' => $upper,
                'name' => self::US_STATES[$upper],
            ];
        }

        $lower = strtolower($trimmed);
        foreach (self::US_STATES as $code => $name) {
            if (strtolower($name) === $lower) {
                return [
                    'code' => $code,
                    'name' => $name,
                ];
            }
        }

        return [
            'code' => null,
            'name' => $trimmed,
        ];
    }

    public function calculate(array $params): array
    {
        $settings = SiteSetting::firstOrCreate([]);
        $taxSettings = $settings->tax_settings ? json_decode($settings->tax_settings, true) : [];

        $enableTaxes = isset($taxSettings['enable_taxes']) 
            ? (bool)$taxSettings['enable_taxes'] 
            : (bool)($settings->tax_enabled ?? true);

        $taxLabel = $taxSettings['tax_label_at_checkout'] ?? 'Sales Tax';
        $calculateAfterDiscounts = isset($taxSettings['calculate_tax_after_discounts']) 
            ? (bool)$taxSettings['calculate_tax_after_discounts'] 
            : true;
        $allowTaxExemption = isset($taxSettings['allow_tax_exemption']) 
            ? (bool)$taxSettings['allow_tax_exemption'] 
            : true;
        $globalTaxOnShipping = isset($taxSettings['tax_on_shipping']) 
            ? (bool)$taxSettings['tax_on_shipping'] 
            : true;

        $subtotal = max(0.00, (float)($params['subtotal'] ?? 0));
        $shipping = max(0.00, (float)($params['shipping_amount'] ?? 0));
        $discount = max(0.00, (float)($params['discount_amount'] ?? 0));
        $isExempt = !empty($params['is_tax_exempt']);

        if (!empty($params['user']) && $params['user'] instanceof User) {
            if (!empty($params['user']->is_tax_exempt)) {
                $isExempt = true;
            }
        }

        if (!$enableTaxes) {
            return [
                'enabled' => false,
                'tax_amount' => 0.00,
                'tax_rate' => 0.00,
                'tax_label' => $taxLabel,
                'state' => $params['state'] ?? null,
                'is_taxable' => false,
                'shipping_taxable' => false,
                'items_taxable_base' => 0.00,
                'shipping_taxable_base' => 0.00,
                'total_taxable_base' => 0.00,
                'exemption_applied' => false,
                'note' => 'Taxes disabled in settings',
            ];
        }

        if ($allowTaxExemption && $isExempt) {
            return [
                'enabled' => true,
                'tax_amount' => 0.00,
                'tax_rate' => 0.00,
                'tax_label' => $taxLabel,
                'state' => $params['state'] ?? null,
                'is_taxable' => false,
                'shipping_taxable' => false,
                'items_taxable_base' => 0.00,
                'shipping_taxable_base' => 0.00,
                'total_taxable_base' => 0.00,
                'exemption_applied' => true,
                'note' => 'Customer is tax exempt',
            ];
        }

        $stateNormalized = self::normalizeState($params['state'] ?? null);
        $stateName = $stateNormalized['name'];
        $stateCode = $stateNormalized['code'];

        $rateRecord = null;
        if ($stateName || $stateCode) {
            $rateRecord = TaxRate::where('is_active', true)
                ->where(function ($q) use ($stateName, $stateCode) {
                    if ($stateName) {
                        $q->whereRaw('LOWER(state) = ?', [strtolower($stateName)]);
                    }
                    if ($stateCode) {
                        $q->orWhereRaw('LOWER(state) = ?', [strtolower($stateCode)]);
                    }
                })
                ->first();
        }

        $taxRate = 0.00;
        $shippingTaxable = false;
        $matchedState = $stateName ?: ($params['state'] ?? null);

        if ($rateRecord) {
            $taxRate = (float)$rateRecord->rate;
            $shippingTaxable = (bool)$rateRecord->shipping_taxable;
            $matchedState = $rateRecord->state;
        } else {
            $fallbackRate = (float)($settings->tax_rate ?? 0);
            if ($fallbackRate > 0) {
                $taxRate = $fallbackRate;
                $shippingTaxable = $globalTaxOnShipping;
            }
        }

        $itemsTaxableBase = $calculateAfterDiscounts 
            ? max(0.00, $subtotal - $discount) 
            : $subtotal;

        $shippingTaxableBase = ($globalTaxOnShipping && $shippingTaxable) ? $shipping : 0.00;
        $totalTaxableBase = $itemsTaxableBase + $shippingTaxableBase;

        $itemsTax = round($itemsTaxableBase * ($taxRate / 100), 2);
        $shippingTax = round($shippingTaxableBase * ($taxRate / 100), 2);
        $totalTax = round($totalTaxableBase * ($taxRate / 100), 2);

        return [
            'enabled' => true,
            'tax_amount' => $totalTax,
            'tax_rate' => $taxRate,
            'tax_label' => $taxLabel,
            'state' => $matchedState,
            'is_taxable' => $taxRate > 0,
            'shipping_taxable' => $shippingTaxable,
            'items_tax_amount' => $itemsTax,
            'shipping_tax_amount' => $shippingTax,
            'items_taxable_base' => round($itemsTaxableBase, 2),
            'shipping_taxable_base' => round($shippingTaxableBase, 2),
            'total_taxable_base' => round($totalTaxableBase, 2),
            'exemption_applied' => false,
            'rate_id' => $rateRecord?->id,
            'rate_label' => $rateRecord?->label,
            'source' => $taxSettings['tax_calculation_source'] ?? 'manual',
        ];
    }
}
