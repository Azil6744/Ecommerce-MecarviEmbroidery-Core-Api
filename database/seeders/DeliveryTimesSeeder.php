<?php

namespace Database\Seeders;

use App\Models\DeliveryTime;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class DeliveryTimesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultTiers = [
            ['min_miles' => 0, 'max_miles' => 6, 'price' => 10.00],
            ['min_miles' => 7, 'max_miles' => 15, 'price' => 15.00],
        ];

        $deliveryTimes = [
            [
                'label' => 'Standard Delivery',
                'estimated_days' => '',
                'description' => 'Regular local delivery for standard orders.',
                'color_code' => '#10B981',
                'pricing' => 10.00,
                'upcharge' => 0.00,
                'mileage_tiers' => $defaultTiers,
                'priority' => 1,
                'status' => true,
            ],
            [
                'label' => 'Priority Delivery',
                'estimated_days' => '',
                'description' => 'Priority express delivery dispatch as soon as your order is ready.',
                'color_code' => '#4F46E5',
                'pricing' => 10.00,
                'upcharge' => 2.00,
                'mileage_tiers' => $defaultTiers,
                'priority' => 2,
                'status' => true,
            ],
            [
                'label' => 'Direct Delivery',
                'estimated_days' => '',
                'description' => 'Dedicated point-to-point courier delivering straight to your location.',
                'color_code' => '#F59E0B',
                'pricing' => 10.00,
                'upcharge' => 10.00,
                'mileage_tiers' => $defaultTiers,
                'priority' => 3,
                'status' => true,
            ],
        ];

        DeliveryTime::truncate();

        foreach ($deliveryTimes as $dt) {
            DeliveryTime::create($dt);
        }

        // Initialize default delivery settings in site_settings
        $settings = SiteSetting::firstOrCreate([]);
        $settings->delivery_settings = json_encode([
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
        ]);
        $settings->save();
    }
}
