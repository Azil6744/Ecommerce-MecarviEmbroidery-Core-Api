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
                    'max_delivery_radius' => 10,
                    'radius_unit' => 'miles',
                    'allow_custom_upcharges' => true,
                    'mileage_tiers' => [
                        ['id' => 'tier_1', 'min_miles' => 0, 'max_miles' => 5, 'price' => 15.00, 'label' => '0 to 5 miles'],
                        ['id' => 'tier_2', 'min_miles' => 6, 'max_miles' => 10, 'price' => 20.00, 'label' => '6 to 10 miles'],
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
