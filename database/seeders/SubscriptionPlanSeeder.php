<?php

namespace Database\Seeders;

use App\Models\EcommerceSubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Personal: Basic Lite
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'EMB-BASIC-LITE'],
            [
                'name' => 'Basic Lite',
                'account_type' => 'personal',
                'price' => 0.00,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Free Plan',
                'description' => "Get started for free.\n\nEnjoy basic member access, save designs to your account, and earn loyalty points on every purchase.",
                'features' => json_encode([
                    'Standard customer pricing',
                    'Earn loyalty points',
                    'Access to member deals',
                    'Save designs to your account'
                ]),
                'benefit_config' => [
                    'theme' => 'pink',
                    'banner_image' => '/assets/membership/banner_basic_lite_hd.png',
                    'percentage_discount' => 0,
                    'fixed_discount' => 0,
                    'free_delivery' => false,
                    'loyalty_multiplier' => 1.0,
                ],
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
                'trial_available' => true,
                'trial_duration_days' => 7,
                'description' => "More value. More benefits.\n\nMecarvi Gold gives you access to exclusive member discounts, free delivery, priority processing and more. Save more on all your embroidery needs.",
                'features' => json_encode([
                    'Discounted member pricing',
                    'Earn 2x loyalty points',
                    'Access to exclusive member deals',
                    'Save unlimited designs',
                    'Priority order processing'
                ]),
                'benefit_config' => [
                    'theme' => 'cyan',
                    'banner_image' => '/assets/membership/banner_mecarvi_gold_hd.png',
                    'percentage_discount' => 10,
                    'fixed_discount' => 0,
                    'free_delivery' => true,
                    'free_delivery_categories' => 'all',
                    'min_order_amount' => 0,
                    'allow_coupon_stacking' => true,
                    'loyalty_multiplier' => 2.0,
                ],
                'sort_order' => 2,
            ]
        );

        // 3. Personal: Mecarvi Platinum One
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'EMB-PLATINUM-ONE'],
            [
                'name' => 'Mecarvi Platinum One',
                'account_type' => 'personal',
                'price' => 19.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Best Value',
                'trial_available' => true,
                'trial_duration_days' => 14,
                'description' => "Premium perks. Maximum benefits.\n\nEnjoy top-tier discounts, 3x reward multiplier, exclusive promotions, and dedicated support on all custom embroidery orders.",
                'features' => json_encode([
                    'Maximum member discounts',
                    'Earn 3x loyalty points',
                    'Access to all member deals',
                    'Save unlimited designs',
                    'Priority order processing',
                    'Exclusive member promotions'
                ]),
                'benefit_config' => [
                    'theme' => 'purple',
                    'banner_image' => '/assets/membership/banner_platinum_one_hd.png',
                    'percentage_discount' => 15,
                    'fixed_discount' => 0,
                    'free_delivery' => true,
                    'free_delivery_categories' => 'all',
                    'min_order_amount' => 0,
                    'allow_coupon_stacking' => true,
                    'loyalty_multiplier' => 3.0,
                ],
                'sort_order' => 3,
            ]
        );

        // 4. Business: Business Starter
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'BUS-STARTER-M'],
            [
                'name' => 'Business Starter',
                'account_type' => 'business',
                'price' => 29.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Starter Tier',
                'trial_available' => true,
                'trial_duration_days' => 14,
                'description' => "Essential tools for small shops.\n\nCommercial volume pricing, dedicated batch digitizing, and multi-user staff logins up to 3 seats.",
                'features' => json_encode([
                    'Commercial volume pricing',
                    'Dedicated batch digitizing',
                    'Multi-user staff logins (up to 3)',
                    'Priority email & chat support'
                ]),
                'benefit_config' => [
                    'theme' => 'blue',
                    'banner_image' => '/assets/membership/banner_business_starter.png',
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

        // 5. Business: Business Pro
        EcommerceSubscriptionPlan::updateOrCreate(
            ['internal_code' => 'BUS-PRO-M'],
            [
                'name' => 'Business Pro',
                'account_type' => 'business',
                'price' => 79.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Most Popular',
                'trial_available' => true,
                'trial_duration_days' => 30,
                'description' => "High-volume commercial power.\n\nWholesale contractor pricing, unlimited digitizing conversions, rush dispatch, and up to 10 staff logins.",
                'features' => json_encode([
                    'Wholesale contractor pricing',
                    'Unlimited digitizing conversions',
                    'Multi-user staff logins (up to 10)',
                    'Rush production dispatch',
                    'Net-30 invoice terms'
                ]),
                'benefit_config' => [
                    'theme' => 'emerald',
                    'banner_image' => '/assets/membership/banner_business_pro.png',
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
            ['internal_code' => 'BUS-ENTERPRISE-ELITE'],
            [
                'name' => 'Enterprise Elite',
                'account_type' => 'business',
                'price' => 199.99,
                'billing_cycle' => 'Monthly',
                'coverage_type' => 'universal',
                'applicable_site' => 'all-sites',
                'status' => 'Active',
                'badge' => 'Enterprise',
                'description' => "Custom capacity & white-glove SLA.\n\nMaximum wholesale margin tier, guaranteed 24h turnaround, custom API & webhooks, Net-60 credit line, and 24/7 dedicated account director.",
                'features' => json_encode([
                    'Maximum wholesale margin tier',
                    'Guaranteed 24h turnaround SLA',
                    'Unlimited team members',
                    'Custom API & webhook integration',
                    'Net-60 flexible credit line',
                    'Dedicated 24/7 account director'
                ]),
                'benefit_config' => [
                    'theme' => 'amber',
                    'banner_image' => '/assets/membership/banner_enterprise_elite.png',
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
