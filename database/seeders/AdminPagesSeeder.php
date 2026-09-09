<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentGateway;
use App\Models\ShippingMethod;
use App\Models\EmailTemplate;
use App\Models\EcommerceOrder;

class AdminPagesSeeder extends Seeder
{
    public function run(): void
    {
        // --- Payment Gateways ---
        $defaultGateways = [
            [
                'name' => 'Stripe',
                'display_label' => 'Credit / Debit Card (Stripe)',
                'provider' => 'stripe',
                'description' => 'Accept Visa, MasterCard, Amex, Apple Pay, and Google Pay securely.',
                'public_key' => 'pk_live_xxxxxxxxxxxx',
                'secret_key' => 'sk_live_xxxxxxxxxxxx',
                'webhook_url' => 'https://api.mecarviembroidery.com/api/webhooks/stripe',
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'PayPal',
                'display_label' => 'PayPal Express & Smart Buttons',
                'provider' => 'paypal',
                'description' => 'Pay securely with PayPal account, balance, Pay in 4, or Venmo.',
                'public_key' => 'paypal_client_id_xxxx',
                'secret_key' => 'paypal_secret_xxxx',
                'webhook_url' => 'https://api.mecarviembroidery.com/api/webhooks/paypal',
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Square',
                'display_label' => 'Square Payments',
                'provider' => 'square',
                'description' => 'Accept credit cards and contactless POS payments with Square.',
                'webhook_url' => 'https://api.mecarviembroidery.com/api/webhooks/square',
                'is_active' => false,
                'is_test_mode' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Cash App Pay',
                'display_label' => 'Cash App Pay',
                'provider' => 'cashapp',
                'description' => 'Pay fast and seamlessly with Cash App on mobile or desktop.',
                'webhook_url' => 'https://api.mecarviembroidery.com/api/webhooks/cashapp',
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'Wallet',
                'display_label' => 'Mecarvi Wallet Balance',
                'provider' => 'wallet',
                'description' => 'Allow customers to pay using their store wallet funds instantly.',
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 5,
            ],
            [
                'name' => 'Gift Cards',
                'display_label' => 'Digital Gift Card Balance',
                'provider' => 'giftcard',
                'description' => 'Redeem your Mecarvi digital gift cards at checkout.',
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 6,
            ],
            [
                'name' => 'Voucher',
                'display_label' => 'Store Voucher & Coupons',
                'provider' => 'voucher',
                'description' => 'Apply promotional voucher codes for order discounts.',
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 7,
            ],
            [
                'name' => 'Cash on Delivery',
                'display_label' => 'Cash on Delivery (COD)',
                'provider' => 'cod',
                'description' => 'Pay with cash upon physical delivery of your order.',
                'is_active' => true,
                'is_test_mode' => false,
                'sort_order' => 8,
            ],
            [
                'name' => 'Bank Transfer',
                'display_label' => 'Direct Bank Transfer / Wire',
                'provider' => 'bank_transfer',
                'description' => 'Make payment directly into our bank account with order ID as reference.',
                'is_active' => false,
                'is_test_mode' => false,
                'sort_order' => 9,
            ],
        ];

        foreach ($defaultGateways as $gw) {
            PaymentGateway::updateOrCreate(['provider' => $gw['provider']], $gw);
        }

        // --- Shipping Methods ---
        ShippingMethod::firstOrCreate(['code' => 'standard'], [
            'name' => 'Standard Shipping',
            'description' => 'Regular delivery via USPS/FedEx Ground',
            'base_rate' => 12.00,
            'estimated_days' => '3-5 business days',
            'coverage' => 'USA',
            'is_active' => true,
            'free_shipping_threshold' => 100.00,
            'sort_order' => 1,
        ]);
        ShippingMethod::firstOrCreate(['code' => 'express'], [
            'name' => 'Express Shipping',
            'description' => 'Priority delivery via FedEx Express',
            'base_rate' => 29.00,
            'estimated_days' => '1-2 business days',
            'coverage' => 'USA',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        ShippingMethod::firstOrCreate(['code' => 'local-pickup'], [
            'name' => 'Local Pickup',
            'description' => 'Pick up from our Houston location',
            'base_rate' => 0.00,
            'estimated_days' => 'Same day',
            'coverage' => 'Houston, TX',
            'is_active' => true,
            'sort_order' => 3,
        ]);
        ShippingMethod::firstOrCreate(['code' => 'international'], [
            'name' => 'International Shipping',
            'description' => 'Worldwide delivery via DHL/FedEx',
            'base_rate' => 45.00,
            'estimated_days' => '7-14 business days',
            'coverage' => 'International',
            'is_active' => false,
            'sort_order' => 4,
        ]);

        // --- Email Templates ---
        EmailTemplate::firstOrCreate(['slug' => 'welcome-email'], [
            'name' => 'Welcome Email',
            'subject' => 'Welcome to Mecarvi!',
            'category' => 'onboarding',
            'preview_text' => 'Thanks for joining our community',
            'body_html' => '<h1>Welcome, {{customer_name}}!</h1><p>We\'re thrilled to have you. Start exploring our products and services.</p><p>Best,<br>The Mecarvi Team</p>',
            'status' => 'published',
            'variables' => ['customer_name', 'customer_email'],
        ]);
        EmailTemplate::firstOrCreate(['slug' => 'order-confirmation'], [
            'name' => 'Order Confirmation',
            'subject' => 'Your Order #{{order_id}} is Confirmed!',
            'category' => 'orders',
            'preview_text' => 'Your order has been placed successfully',
            'body_html' => '<h1>Order Confirmed!</h1><p>Hi {{customer_name}},</p><p>Your order <strong>#{{order_id}}</strong> has been received and is being processed.</p><p>Total: {{order_total}}</p><p>Estimated delivery: {{estimated_delivery}}</p>',
            'status' => 'published',
            'variables' => ['customer_name', 'order_id', 'order_total', 'estimated_delivery'],
        ]);
        EmailTemplate::firstOrCreate(['slug' => 'shipping-notification'], [
            'name' => 'Shipping Notification',
            'subject' => 'Your Order #{{order_id}} Has Shipped!',
            'category' => 'orders',
            'preview_text' => 'Your order is on its way',
            'body_html' => '<h1>Your order is on its way!</h1><p>Hi {{customer_name}},</p><p>Order <strong>#{{order_id}}</strong> has been shipped.</p><p>Tracking: <a href="{{tracking_url}}">{{tracking_number}}</a></p>',
            'status' => 'published',
            'variables' => ['customer_name', 'order_id', 'tracking_url', 'tracking_number'],
        ]);
        EmailTemplate::firstOrCreate(['slug' => 'abandoned-cart'], [
            'name' => 'Abandoned Cart Reminder',
            'subject' => 'You left something behind!',
            'category' => 'sales',
            'preview_text' => 'Complete your purchase today',
            'body_html' => '<h1>Forgot Something?</h1><p>Hi {{customer_name}},</p><p>You have items waiting in your cart. Complete your purchase before they sell out!</p>',
            'status' => 'draft',
            'variables' => ['customer_name', 'cart_url'],
        ]);
        EmailTemplate::firstOrCreate(['slug' => 'password-reset'], [
            'name' => 'Password Reset',
            'subject' => 'Reset Your Password',
            'category' => 'system',
            'preview_text' => 'Password reset requested',
            'body_html' => '<h1>Password Reset</h1><p>Hi {{customer_name}},</p><p>Click the link below to reset your password:</p><p><a href="{{reset_url}}">Reset Password</a></p><p>This link expires in 60 minutes.</p>',
            'status' => 'published',
            'variables' => ['customer_name', 'reset_url'],
        ]);

        // --- Sample Orders (if none exist) ---
        if (EcommerceOrder::count() === 0) {
            $user = \App\Models\User::first();
            if ($user) {
                $statuses = ['pending', 'processing', 'completed', 'cancelled'];
                $names = ['John Doe', 'Jane Smith', 'Alex Johnson', 'Sarah Kim', 'Chris Moon', 'Emma Reed', 'Ryan Cole', 'Nina Cole', 'Brett Kim', 'Tina Brooks', 'Jordan Lee', 'Martha Jones'];
                for ($i = 0; $i < 12; $i++) {
                    EcommerceOrder::create([
                        'user_id' => $user->id,
                        'order_number' => EcommerceOrder::generateOrderNumber(),
                        'customer_name' => $names[$i],
                        'customer_email' => strtolower(str_replace(' ', '.', $names[$i])) . '@example.com',
                        'status' => $statuses[array_rand($statuses)],
                        'total_amount' => rand(5000, 50000) / 100,
                        'order_date' => now()->subDays(rand(0, 30)),
                    ]);
                }
            }
        }

        $this->command->info('✓ Admin pages data seeded successfully.');
        $this->command->info('  Payment Gateways: ' . PaymentGateway::count());
        $this->command->info('  Shipping Methods: ' . ShippingMethod::count());
        $this->command->info('  Email Templates: ' . EmailTemplate::count());
        $this->command->info('  Orders: ' . EcommerceOrder::count());
    }
}
