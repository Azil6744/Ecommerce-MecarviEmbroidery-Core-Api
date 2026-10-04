<?php

namespace App\Http\Controllers\Api\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTime;
use App\Models\SiteSetting;

class DeliveryTimeController extends Controller
{
    public function index()
    {
        try {
            $deliveryTimes = DeliveryTime::where('status', true)
                ->orderBy('priority')
                ->get();

            $settings = SiteSetting::first();
            $deliverySettings = $settings && $settings->delivery_settings
                ? json_decode($settings->delivery_settings, true)
                : [
                    'max_delivery_radius' => 25,
                    'radius_unit' => 'miles',
                    'allow_custom_upcharges' => true,
                    'mileage_tiers' => [
                        ['id' => 'tier_1', 'min_miles' => 0, 'max_miles' => 5, 'price' => 15.00, 'label' => '0 to 5 miles'],
                        ['id' => 'tier_2', 'min_miles' => 6, 'max_miles' => 10, 'price' => 20.00, 'label' => '6 to 10 miles'],
                        ['id' => 'tier_3', 'min_miles' => 11, 'max_miles' => 15, 'price' => 25.00, 'label' => '11 to 15 miles'],
                        ['id' => 'tier_4', 'min_miles' => 16, 'max_miles' => 20, 'price' => 30.00, 'label' => '16 to 20 miles'],
                        ['id' => 'tier_5', 'min_miles' => 21, 'max_miles' => 25, 'price' => 35.00, 'label' => '21 to 25 miles'],
                    ]
                ];

            return response()->json([
                'success' => true,
                'data' => $deliveryTimes,
                'settings' => $deliverySettings,
                'delivery_settings' => $deliverySettings,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivery times.',
            ], 500);
        }
    }
}
