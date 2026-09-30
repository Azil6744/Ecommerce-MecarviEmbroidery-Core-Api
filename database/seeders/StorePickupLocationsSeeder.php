<?php

namespace Database\Seeders;

use App\Models\StorePickupLocation;
use Illuminate\Database\Seeder;

class StorePickupLocationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $weeklyHours = [
            'Monday' => ['open' => '09:00 AM', 'close' => '07:00 PM', 'breakOpen' => '01:00 PM', 'breakClose' => '02:00 PM', 'status' => 'open'],
            'Tuesday' => ['open' => '09:00 AM', 'close' => '07:00 PM', 'breakOpen' => '01:00 PM', 'breakClose' => '02:00 PM', 'status' => 'open'],
            'Wednesday' => ['open' => '09:00 AM', 'close' => '07:00 PM', 'breakOpen' => '01:00 PM', 'breakClose' => '02:00 PM', 'status' => 'open'],
            'Thursday' => ['open' => '09:00 AM', 'close' => '07:00 PM', 'breakOpen' => '01:00 PM', 'breakClose' => '02:00 PM', 'status' => 'open'],
            'Friday' => ['open' => '09:00 AM', 'close' => '07:00 PM', 'breakOpen' => '01:00 PM', 'breakClose' => '02:00 PM', 'status' => 'open'],
            'Saturday' => ['open' => '10:00 AM', 'close' => '05:00 PM', 'breakOpen' => '01:00 PM', 'breakClose' => '02:00 PM', 'status' => 'open'],
            'Sunday' => ['open' => '09:00 AM', 'close' => '05:00 PM', 'breakOpen' => '01:00 PM', 'breakClose' => '02:00 PM', 'status' => 'closed'],
        ];

        $specialHours = [
            [
                'id' => '1',
                'date' => 'May 26, 2026',
                'occasion' => 'Memorial Day',
                'open' => '10:00 AM',
                'close' => '04:00 PM',
                'status' => true
            ]
        ];

        $locations = [
            [
                'name' => 'Mecarvi Embroidery - McDonough',
                'code' => 'MEC-MCD-001',
                'store_type' => 'Company Store',
                'timezone' => 'Eastern Standard Time (EST)',
                'address' => '233 Stray Way Circle, Suite B, McDonough, GA 30253, United States',
                'phone' => '(678) 432-9876',
                'notes' => 'Headquarters flagship store.',
                'short_description' => 'Pick up your orders quickly and easily from our McDonough location.',
                'image_path' => '/assets/images/storefront_placeholder.png',
                'status' => true,
                'is_pickup_enabled' => true,
                'pickup_preparation_time' => 2,
                'pickup_preparation_unit' => 'Hours',
                'max_pickup_radius' => 10.0,
                'latitude' => 33.4473,
                'longitude' => -84.1469,
                'weekly_schedule' => $weeklyHours,
                'special_hours' => $specialHours,
                'max_future_days' => '7 Days',
                'notif_order_ready' => true,
                'notif_order_picked_up' => true,
                'notif_new_pickup_alert' => true,
                'cust_notif_email' => true,
                'cust_notif_sms' => true,
                'staff_notif_email' => true,
                'staff_notif_sms' => true,
                'id_verification_required' => true,
                'allow_pickup_by_others' => true,
                'pickup_code_required' => true,
                'pickup_code_expiry' => '24 Hours',
                'order_hold_duration' => '7 Days',
                'late_pickup_action' => 'Cancel Order',
                'refund_policy' => 'Refund to Original Payment Method',
                'allow_partial_pickup' => true,
                'show_pickup_instructions' => true,
                'additional_pickup_instructions' => 'Please bring a valid ID and the order confirmation email when picking up your order.',
            ],
            [
                'name' => 'Mecarvi Embroidery - Atlanta',
                'code' => 'MEC-ATL-002',
                'store_type' => 'Company Store',
                'timezone' => 'Eastern Standard Time (EST)',
                'address' => '3650 Peachtree Rd NE, Suite 120, Atlanta, GA 30326, United States',
                'phone' => '(404) 555-0198',
                'notes' => 'Central business hub.',
                'short_description' => 'Premium Atlanta facility pickup hub.',
                'image_path' => '/assets/images/storefront_placeholder.png',
                'status' => true,
                'is_pickup_enabled' => true,
                'pickup_preparation_time' => 4,
                'pickup_preparation_unit' => 'Hours',
                'max_pickup_radius' => 10.0,
                'latitude' => 33.8539,
                'longitude' => -84.3619,
                'weekly_schedule' => $weeklyHours,
                'special_hours' => [],
                'max_future_days' => '14 Days',
                'notif_order_ready' => true,
                'notif_order_picked_up' => true,
                'notif_new_pickup_alert' => true,
                'cust_notif_email' => true,
                'cust_notif_sms' => false,
                'staff_notif_email' => true,
                'staff_notif_sms' => true,
                'id_verification_required' => true,
                'allow_pickup_by_others' => true,
                'pickup_code_required' => true,
                'pickup_code_expiry' => '48 Hours',
                'order_hold_duration' => '14 Days',
                'late_pickup_action' => 'Charge Holding Fee',
                'refund_policy' => 'Refund as Store Credit',
                'allow_partial_pickup' => false,
                'show_pickup_instructions' => true,
                'additional_pickup_instructions' => 'Call ahead 15 minutes before arrival for curbside pickup.',
            ],
            [
                'name' => 'Mecarvi Embroidery - Norcross',
                'code' => 'MEC-NRX-003',
                'store_type' => 'Partner Store',
                'timezone' => 'Eastern Standard Time (EST)',
                'address' => '5865 Jimmy Carter Blvd, Suite 200, Norcross, GA 30093, United States',
                'phone' => '(770) 123-4567',
                'notes' => 'Norcross distribution center.',
                'short_description' => 'Norcross distribution and printing center.',
                'image_path' => '/assets/images/storefront_placeholder.png',
                'status' => true,
                'is_pickup_enabled' => true,
                'pickup_preparation_time' => 2,
                'pickup_preparation_unit' => 'Hours',
                'max_pickup_radius' => 10.0,
                'latitude' => 33.9189,
                'longitude' => -84.1894,
                'weekly_schedule' => $weeklyHours,
                'special_hours' => [],
                'max_future_days' => '7 Days',
                'notif_order_ready' => true,
                'notif_order_picked_up' => true,
                'notif_new_pickup_alert' => false,
                'cust_notif_email' => true,
                'cust_notif_sms' => true,
                'staff_notif_email' => true,
                'staff_notif_sms' => false,
                'id_verification_required' => false,
                'allow_pickup_by_others' => true,
                'pickup_code_required' => true,
                'pickup_code_expiry' => '24 Hours',
                'order_hold_duration' => '7 Days',
                'late_pickup_action' => 'Cancel Order',
                'refund_policy' => 'Refund to Original Payment Method',
                'allow_partial_pickup' => true,
                'show_pickup_instructions' => true,
                'additional_pickup_instructions' => 'Pick up at Counter 3 inside the main entrance.',
            ],
        ];

        foreach ($locations as $locData) {
            StorePickupLocation::updateOrCreate(
                ['code' => $locData['code']],
                $locData
            );
        }
    }
}
