<?php

namespace Database\Seeders;

use App\Models\EcommerceCoupon;
use App\Models\EcommerceOrder;
use App\Models\EcommerceGiftCard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class BusinessAccountSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure business and seller roles exist
        try {
            Role::firstOrCreate(['name' => 'business', 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => 'seller', 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        } catch (\Throwable $e) {
            // Role table might not exist in some local db setups
        }

        // 2. Primary Business Account: Marcus Thomas / StitchPro Designs LLC (Matches Reference Design)
        $bizUser1 = User::updateOrCreate(
            ['email' => 'marcus@stitchprodesigns.com'],
            [
                'name' => 'Marcus Thomas',
                'password' => Hash::make('Business@123456'),
                'customer_account_number' => 'BUS-00012456',
                'phone' => '+1 (473) 405-7896',
                'address' => '123 Grand Anse Main Road, St. George\'s, Grenada',
                'dob' => Carbon::create(1988, 5, 12),
                'gender' => 'Male',
                'membership_status' => 'Gold Member',
                'role' => 'business',
                'business_name' => 'StitchPro Designs LLC',
                'tax_id' => '32-4567890',
                'business_type' => 'Business Pro',
                'avatar' => '', // Will show purple building logo
                'email_verified_at' => Carbon::now()->subMonths(12),
                'created_at' => Carbon::create(2023, 5, 12, 10, 0, 0),
                'loyalty_points' => 4875,
            ]
        );

        try {
            if (!$bizUser1->hasRole('business')) {
                $bizUser1->assignRole('business');
            }
        } catch (\Throwable $e) {}

        // 3. Secondary Business Account: TopStitch Embroidery Ltd
        $bizUser2 = User::updateOrCreate(
            ['email' => 'seller@mecarvi.com'],
            [
                'name' => 'Sarah Jenkins',
                'password' => Hash::make('Seller@123456'),
                'customer_account_number' => 'BUS-0000028',
                'phone' => '+1 (473) 405-7896',
                'address' => 'Grand Anse, St. George\'s Grenada, W.I.',
                'dob' => Carbon::create(1992, 7, 30),
                'gender' => 'Female',
                'membership_status' => 'Basic Lite',
                'role' => 'business',
                'business_name' => 'TopStitch Embroidery Ltd',
                'tax_id' => 'TAX-GD-8849201',
                'business_type' => 'Textile & Embroidery (LLC)',
                'avatar' => '',
                'email_verified_at' => Carbon::now()->subMonths(6),
                'created_at' => Carbon::create(2026, 7, 30, 9, 0, 0),
                'loyalty_points' => 4875,
            ]
        );

        try {
            if (!$bizUser2->hasRole('business')) {
                $bizUser2->assignRole('business');
            }
        } catch (\Throwable $e) {}

        // 4. Seed Reference Coupons matching screenshot 2
        $referenceCoupons = [
            [
                'code' => 'MARCUS20',
                'title' => '20% OFF',
                'subtitle' => 'Special Business Promo',
                'discount_type' => 'percentage',
                'discount_value' => 20.00,
                'min_order_amount' => 50.00,
                'usage_limit' => 1,
                'used_count' => 1,
                'starts_at' => Carbon::now()->subDays(10),
                'expires_at' => Carbon::now()->addDays(20),
                'is_active' => true,
                'metadata' => [
                    'applies_to' => 'All products',
                    'color' => '#ef0b6e',
                    'status' => 'Active',
                    'valid_from' => 'Jun 1, 2026',
                    'valid_to' => 'Jun 30, 2026',
                    'usage' => '1 / 1 Used',
                ],
            ],
            [
                'code' => 'FREESHIP',
                'title' => 'FREE SHIPPING',
                'subtitle' => 'Free Delivery on All Items',
                'discount_type' => 'free_shipping',
                'discount_value' => 0.00,
                'min_order_amount' => 0.00,
                'usage_limit' => 1,
                'used_count' => 0,
                'starts_at' => Carbon::now()->subDays(5),
                'expires_at' => Carbon::now()->addDays(25),
                'is_active' => true,
                'metadata' => [
                    'applies_to' => 'All products',
                    'color' => '#6366f1',
                    'status' => 'Active',
                    'valid_from' => 'May 15, 2026',
                    'valid_to' => 'May 31, 2026',
                    'usage' => '0 / 1 Used',
                ],
            ],
            [
                'code' => 'WELCOME10',
                'title' => '10% OFF',
                'subtitle' => 'Welcome Customer Discount',
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'min_order_amount' => 30.00,
                'usage_limit' => 1,
                'used_count' => 1,
                'starts_at' => Carbon::now()->subDays(15),
                'expires_at' => Carbon::now()->addDays(15),
                'is_active' => true,
                'metadata' => [
                    'applies_to' => 'All products',
                    'color' => '#f97316',
                    'status' => 'Active',
                    'valid_from' => 'Apr 10, 2026',
                    'valid_to' => 'Apr 30, 2026',
                    'usage' => '1 / 1 Used',
                ],
            ],
            [
                'code' => 'BIRTHDAY15',
                'title' => '15% OFF',
                'subtitle' => 'Upcoming Birthday Deal',
                'discount_type' => 'percentage',
                'discount_value' => 15.00,
                'min_order_amount' => 40.00,
                'usage_limit' => 1,
                'used_count' => 0,
                'starts_at' => Carbon::now()->addDays(10),
                'expires_at' => Carbon::now()->addDays(40),
                'is_active' => true,
                'metadata' => [
                    'applies_to' => 'All products',
                    'color' => '#0070f3',
                    'status' => 'Scheduled',
                    'valid_from' => 'Jun 15, 2026',
                    'valid_to' => 'Jun 30, 2026',
                    'usage' => '0 / 1 Used',
                ],
            ],
            [
                'code' => 'FLASHSALES',
                'title' => '$5 OFF',
                'subtitle' => 'Flash Sale Promo',
                'discount_type' => 'fixed',
                'discount_value' => 5.00,
                'min_order_amount' => 25.00,
                'usage_limit' => 1,
                'used_count' => 1,
                'starts_at' => Carbon::now()->subDays(30),
                'expires_at' => Carbon::now()->subDays(25),
                'is_active' => false,
                'metadata' => [
                    'applies_to' => 'Selected products',
                    'color' => '#00b074',
                    'status' => 'Expired',
                    'valid_from' => 'Apr 20, 2026',
                    'valid_to' => 'Apr 22, 2026',
                    'usage' => '1 / 1 Used',
                ],
            ],
            [
                'code' => 'VIPEXTRA',
                'title' => '5% OFF',
                'subtitle' => 'VIP Extra Savings',
                'discount_type' => 'percentage',
                'discount_value' => 5.00,
                'min_order_amount' => 60.00,
                'usage_limit' => 2,
                'used_count' => 0,
                'starts_at' => Carbon::now()->subDays(8),
                'expires_at' => Carbon::now()->addDays(22),
                'is_active' => true,
                'metadata' => [
                    'applies_to' => 'All products',
                    'color' => '#00a8cc',
                    'status' => 'Active',
                    'valid_from' => 'May 1, 2026',
                    'valid_to' => 'May 31, 2026',
                    'usage' => '0 / 2 Used',
                ],
            ],
        ];

        foreach ($referenceCoupons as $couponData) {
            EcommerceCoupon::updateOrCreate(
                ['code' => $couponData['code']],
                $couponData
            );
        }

        // 5. Seed Orders for Marcus Thomas to match metric ($24,850.75 across orders)
        $existingOrderCount = EcommerceOrder::where('user_id', $bizUser1->id)->count();
        if ($existingOrderCount < 5) {
            $orderTemplates = [
                ['order_number' => 'ORD-2026-00156', 'total_amount' => 8450.75, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => Carbon::now()->subDays(2)],
                ['order_number' => 'ORD-2026-00155', 'total_amount' => 6200.00, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => Carbon::now()->subDays(5)],
                ['order_number' => 'ORD-2026-00154', 'total_amount' => 5100.00, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => Carbon::now()->subDays(10)],
                ['order_number' => 'ORD-2026-00153', 'total_amount' => 3200.00, 'status' => 'shipped', 'payment_status' => 'paid', 'created_at' => Carbon::now()->subDays(14)],
                ['order_number' => 'ORD-2026-00152', 'total_amount' => 1900.00, 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => Carbon::now()->subDays(20)],
            ];

            foreach ($orderTemplates as $ord) {
                EcommerceOrder::create([
                    'order_number' => $ord['order_number'],
                    'order_date' => $ord['created_at'],
                    'user_id' => $bizUser1->id,
                    'customer_name' => $bizUser1->name,
                    'customer_email' => $bizUser1->email,
                    'customer_phone' => $bizUser1->phone,
                    'shipping_address' => $bizUser1->address,
                    'billing_address' => $bizUser1->address,
                    'subtotal' => $ord['total_amount'] * 0.9,
                    'tax_amount' => $ord['total_amount'] * 0.1,
                    'total_amount' => $ord['total_amount'],
                    'status' => $ord['status'],
                    'payment_status' => $ord['payment_status'],
                    'payment_method' => 'Credit Card',
                    'created_at' => $ord['created_at'],
                    'updated_at' => $ord['created_at'],
                ]);
            }
        }

        // 6. Seed Gift Card for Marcus Thomas ($350.00)
        try {
            if (EcommerceGiftCard::where('user_id', $bizUser1->id)->count() === 0) {
                EcommerceGiftCard::create([
                    'code' => 'GC-STITCHPRO-350',
                    'user_id' => $bizUser1->id,
                    'recipient_email' => $bizUser1->email,
                    'initial_value' => 350.00,
                    'current_balance' => 350.00,
                    'status' => 'active',
                    'expires_at' => Carbon::now()->addYear(),
                ]);
            }
        } catch (\Throwable $e) {}

        // 7. Seed Referral Commission ($620.00)
        try {
            if (DB::getSchemaBuilder()->hasTable('ecommerce_referral_commissions')) {
                DB::table('ecommerce_referral_commissions')->insertOrIgnore([
                    'referrer_id' => $bizUser1->id,
                    'referred_user_id' => $bizUser2->id,
                    'commission_amount' => 620.00,
                    'status' => 'paid',
                    'created_at' => Carbon::now()->subDays(15),
                    'updated_at' => Carbon::now()->subDays(15),
                ]);
            }
        } catch (\Throwable $e) {}

        $this->command->info('Business accounts and coupons seeded successfully!');
        $this->command->line('Business 1: marcus@stitchprodesigns.com (StitchPro Designs LLC)');
        $this->command->line('Business 2: seller@mecarvi.com (TopStitch Embroidery Ltd)');
    }
}
