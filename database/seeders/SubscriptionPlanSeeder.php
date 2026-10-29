<?php

namespace Database\Seeders;

use App\Models\EcommerceSubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Personal: Free Plan / Basic Lite
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'FREE-PLAN'],
            [
                'name' => 'Free Plan',
                'account_type' => 'personal',
                'price' => 0.00,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Free Access',
                'description' => 'Basic access to catalog browsing and quotes.',
                'features' => json_encode(['Basic support', 'Catalog browsing', 'Save quotes to account', 'Standard shipping rates']),
                'sort_order' => 1,
            ]
        );

        // 2. Personal: Mecarvi Gold
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'EMB-MECARVI-GOLD'],
            [
                'name' => 'Mecarvi Gold',
                'account_type' => 'personal',
                'price' => 9.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Most Popular',
                'description' => 'More value, more benefits, and double points.',
                'features' => json_encode(['5% Site-wide Discount', 'Earn 2x loyalty points', 'Exclusive member deals', 'Save unlimited designs', 'Priority order processing']),
                'benefit_config' => [
                    'percentage_discount' => 5,
                    'fixed_discount' => 0,
                    'free_delivery' => false,
                    'free_delivery_categories' => 'none',
                    'min_order_amount' => 0,
                    'allow_coupon_stacking' => true,
                    'loyalty_multiplier' => 2.0,
                ],
                'sort_order' => 2,
            ]
        );

        // 3. Personal: Essentials Plan
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'BASIC-PLAN'],
            [
                'name' => 'Essentials Plan',
                'account_type' => 'personal',
                'price' => 19.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Popular Choice',
                'description' => 'Great for regular embroidery orders and digitizing.',
                'features' => json_encode(['10% Site-wide Discount', 'Free Delivery on all orders', 'Email & chat support', 'Early access to new designs', 'Reward points on every order']),
                'benefit_config' => [
                    'percentage_discount' => 10,
                    'fixed_discount' => 0,
                    'free_delivery' => true,
                    'free_delivery_categories' => 'all',
                    'min_order_amount' => 0,
                    'allow_coupon_stacking' => true,
                    'loyalty_multiplier' => 1.5,
                ],
                'sort_order' => 3,
            ]
        );

        // 4. Business: Business Starter
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'EMB-BUS-STARTER'],
            [
                'name' => 'Business Starter',
                'account_type' => 'business',
                'price' => 29.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Starter Tier',
                'description' => 'Essential tools and commercial volume pricing for small shops.',
                'features' => json_encode(['15% Site-wide Discount', 'Commercial volume pricing', 'Free Standard Delivery', 'Save unlimited company designs', 'Dedicated phone support']),
                'benefit_config' => [
                    'percentage_discount' => 15,
                    'fixed_discount' => 0,
                    'free_delivery' => true,
                    'free_delivery_categories' => 'all',
                    'min_order_amount' => 0,
                    'allow_coupon_stacking' => true,
                    'loyalty_multiplier' => 1.8,
                ],
                'sort_order' => 4,
            ]
        );

        // 5. Business: Business Pro Plan
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'BUS-PRO'],
            [
                'name' => 'Business Pro Plan',
                'account_type' => 'business',
                'price' => 49.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Best For Businesses',
                'description' => 'Maximum discounts, priority production, and dedicated manager.',
                'features' => json_encode(['20% Site-wide Discount', 'Bulk pricing & quantity discounts', 'Faster production & priority shipping', 'Dedicated account manager', 'Monthly $50 store credit', 'Priority support & custom solutions']),
                'benefit_config' => [
                    'percentage_discount' => 20,
                    'fixed_discount' => 0,
                    'free_delivery' => true,
                    'free_delivery_categories' => 'all',
                    'min_order_amount' => 0,
                    'allow_coupon_stacking' => true,
                    'loyalty_multiplier' => 2.0,
                ],
                'sort_order' => 5,
            ]
        );

        // 6. Business: Enterprise Elite
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'EMB-ENTERPRISE-ELITE'],
            [
                'name' => 'Enterprise Elite',
                'account_type' => 'business',
                'price' => 99.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Enterprise Tier',
                'description' => 'Full corporate customization, tax exemption, and dedicated executive support.',
                'features' => json_encode(['30% Site-wide Discount', 'Custom solution development', 'Unlimited multi-seat team accounts', 'Priority overnight shipping waived', 'Monthly $100 store credit', 'Dedicated executive account manager']),
                'benefit_config' => [
                    'percentage_discount' => 30,
                    'fixed_discount' => 0,
                    'free_delivery' => true,
                    'free_delivery_categories' => 'all',
                    'min_order_amount' => 0,
                    'allow_coupon_stacking' => true,
                    'loyalty_multiplier' => 3.0,
                ],
                'sort_order' => 6,
            ]
        );
    }
}
