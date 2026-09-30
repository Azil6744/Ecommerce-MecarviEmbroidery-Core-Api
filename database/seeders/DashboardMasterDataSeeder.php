<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderItem;
use App\Models\EcommerceQuotation;
use App\Models\EcommerceTicket;
use App\Models\EcommerceDispute;
use App\Models\EcommerceConversation;
use App\Models\EcommerceConversationMessage;
use App\Models\EcommerceGiftCard;
use App\Models\EcommerceAffiliate;
use App\Models\EcommerceSubscriptionPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DashboardMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding comprehensive dashboard master data...');

        // 1. Seed Real Customers
        $customersData = [
            ['name' => 'Michael Johnson', 'email' => 'michael.johnson@apexbrand.com', 'phone' => '+1 (404) 555-0142'],
            ['name' => 'Sarah Williams', 'email' => 'sarah.williams@threadsapparel.io', 'phone' => '+1 (512) 555-0189'],
            ['name' => 'David Brown', 'email' => 'david.brown@summitoutdoor.com', 'phone' => '+1 (206) 555-0177'],
            ['name' => 'Emily Davis', 'email' => 'emily.davis@creativehub.co', 'phone' => '+1 (312) 555-0123'],
            ['name' => 'James Wilson', 'email' => 'james.wilson@vanguardstitch.com', 'phone' => '+1 (617) 555-0195'],
            ['name' => 'Amanda Thomas', 'email' => 'amanda.thomas@urbanpulse.net', 'phone' => '+1 (702) 555-0164'],
            ['name' => 'Christopher Lee', 'email' => 'chris.lee@pacificgear.com', 'phone' => '+1 (415) 555-0138'],
            ['name' => 'Jessica Miller', 'email' => 'jessica.m@modernstitch.org', 'phone' => '+1 (303) 555-0155'],
            ['name' => 'Robert Anderson', 'email' => 'robert.a@beaconembroidery.com', 'phone' => '+1 (214) 555-0112'],
            ['name' => 'John Smith', 'email' => 'john.smith@smithcrafts.com', 'phone' => '+1 (602) 555-0190'],
        ];

        $seededUsers = [];
        foreach ($customersData as $c) {
            $user = User::updateOrCreate(
                ['email' => $c['email']],
                [
                    'name' => $c['name'],
                    'phone' => $c['phone'],
                    'password' => Hash::make('Password@123'),
                    'role' => 'customer',
                    'email_verified_at' => now()->subDays(rand(1, 60)),
                    'created_at' => now()->subDays(rand(1, 45)),
                ]
            );
            $seededUsers[] = $user;
        }

        // 2. Seed Business Users
        $businessData = [
            ['name' => 'Marcus Vance', 'email' => 'marcus@stitchprodesigns.com', 'company' => 'StitchPro Designs LLC', 'phone' => '+1 (832) 555-0199'],
            ['name' => 'Elena Rostova', 'email' => 'elena@topstitchemb.com', 'company' => 'TopStitch Embroidery Ltd', 'phone' => '+1 (713) 555-0188'],
            ['name' => 'Arthur Pendelton', 'email' => 'arthur@quickbuildapparel.com', 'company' => 'QuickBuild Inc.', 'phone' => '+1 (212) 555-0133'],
            ['name' => 'Chloe Bennett', 'email' => 'chloe@elitesportswear.net', 'company' => 'Elite Sportswear Group', 'phone' => '+1 (310) 555-0144'],
            ['name' => 'Gregory Hayes', 'email' => 'greg@greenleafsolutions.io', 'company' => 'Green Leaf Solutions', 'phone' => '+1 (503) 555-0176'],
            ['name' => 'Tara Sterling', 'email' => 'tara@goldenthreads.com', 'company' => 'GoldenThreads Atelier', 'phone' => '+1 (404) 555-0150'],
        ];

        foreach ($businessData as $b) {
            User::updateOrCreate(
                ['email' => $b['email']],
                [
                    'name' => $b['name'],
                    'phone' => $b['phone'],
                    'business_name' => $b['company'],
                    'password' => Hash::make('Password@123'),
                    'role' => 'business',
                    'email_verified_at' => now()->subDays(rand(5, 90)),
                    'created_at' => now()->subDays(rand(2, 60)),
                ]
            );
        }

        // 3. Seed Realistic Orders Across Date Spans
        $orderTemplates = [
            [
                'number' => 'ORD-2026-12987',
                'customer' => $seededUsers[0],
                'status' => 'processing',
                'subtotal' => 84.40,
                'shipping' => 5.00,
                'total' => 89.40,
                'days_ago' => 0,
                'items' => [
                    ['name' => 'Custom Logo 3D Puff Snapback Cap', 'sku' => 'CAP-WHT-01', 'qty' => 2, 'price' => 42.20],
                ]
            ],
            [
                'number' => 'ORD-2026-12986',
                'customer' => $seededUsers[1],
                'status' => 'processing',
                'subtotal' => 146.70,
                'shipping' => 10.00,
                'total' => 156.70,
                'days_ago' => 0,
                'items' => [
                    ['name' => 'Embroidered Heavyweight Organic Hoodie', 'sku' => 'HD-GRN-02', 'qty' => 2, 'price' => 73.35],
                ]
            ],
            [
                'number' => 'ORD-2026-12985',
                'customer' => $seededUsers[2],
                'status' => 'processing',
                'subtotal' => 199.30,
                'shipping' => 15.00,
                'total' => 214.30,
                'days_ago' => 1,
                'items' => [
                    ['name' => 'Custom Heather Grey Zip Hoodie', 'sku' => 'HD-GRY-03', 'qty' => 3, 'price' => 66.43],
                ]
            ],
            [
                'number' => 'ORD-2026-12984',
                'customer' => $seededUsers[3],
                'status' => 'processing',
                'subtotal' => 102.90,
                'shipping' => 10.00,
                'total' => 112.90,
                'days_ago' => 1,
                'items' => [
                    ['name' => 'Navy Classic Wool Blend Cap', 'sku' => 'CAP-NVY-04', 'qty' => 3, 'price' => 34.30],
                ]
            ],
            [
                'number' => 'ORD-2026-12982',
                'customer' => $seededUsers[4],
                'status' => 'completed',
                'subtotal' => 70.20,
                'shipping' => 5.00,
                'total' => 75.20,
                'days_ago' => 2,
                'items' => [
                    ['name' => 'Premium Ring-Spun Embroidered Tee', 'sku' => 'TSH-WHT-05', 'qty' => 3, 'price' => 23.40],
                ]
            ],
            [
                'number' => 'ORD-2026-12978',
                'customer' => $seededUsers[5],
                'status' => 'completed',
                'subtotal' => 40.60,
                'shipping' => 5.00,
                'total' => 45.60,
                'days_ago' => 3,
                'items' => [
                    ['name' => 'Matte Black Custom Duffle Bag', 'sku' => 'BAG-BLK-06', 'qty' => 1, 'price' => 40.60],
                ]
            ],
            [
                'number' => 'ORD-2026-12970',
                'customer' => $seededUsers[6],
                'status' => 'completed',
                'subtotal' => 620.00,
                'shipping' => 25.00,
                'total' => 645.00,
                'days_ago' => 4,
                'items' => [
                    ['name' => 'Corporate Pique Polos (Bulk 20x)', 'sku' => 'POL-BLK-07', 'qty' => 20, 'price' => 31.00],
                ]
            ],
            [
                'number' => 'ORD-2026-12960',
                'customer' => $seededUsers[7],
                'status' => 'completed',
                'subtotal' => 340.00,
                'shipping' => 18.00,
                'total' => 358.00,
                'days_ago' => 5,
                'items' => [
                    ['name' => 'Embroidered Chef Aprons & Caps', 'sku' => 'APR-WHT-08', 'qty' => 10, 'price' => 34.00],
                ]
            ],
            [
                'number' => 'ORD-2026-12950',
                'customer' => $seededUsers[8],
                'status' => 'completed',
                'subtotal' => 880.00,
                'shipping' => 30.00,
                'total' => 910.00,
                'days_ago' => 6,
                'items' => [
                    ['name' => 'Softshell Windbreakers with 3-Position Embroidery', 'sku' => 'JKT-BLU-09', 'qty' => 12, 'price' => 73.33],
                ]
            ],
            [
                'number' => 'ORD-2026-12940',
                'customer' => $seededUsers[9],
                'status' => 'completed',
                'subtotal' => 1200.00,
                'shipping' => 45.00,
                'total' => 1245.00,
                'days_ago' => 7,
                'items' => [
                    ['name' => 'Varsity Bomber Jackets Deluxe Set', 'sku' => 'JKT-VRS-10', 'qty' => 8, 'price' => 150.00],
                ]
            ],
        ];

        foreach ($orderTemplates as $ot) {
            $orderDate = Carbon::now()->subDays($ot['days_ago'])->subHours(rand(1, 10));
            $order = EcommerceOrder::updateOrCreate(
                ['order_number' => $ot['number']],
                [
                    'user_id' => $ot['customer']->id,
                    'customer_name' => $ot['customer']->name,
                    'customer_email' => $ot['customer']->email,
                    'customer_phone' => $ot['customer']->phone,
                    'company_name' => 'Mecarvi Enterprise',
                    'status' => $ot['status'],
                    'payment_status' => 'paid',
                    'payment_method' => 'Credit Card',
                    'shipping_method' => 'FedEx Express',
                    'currency' => 'USD',
                    'subtotal' => $ot['subtotal'],
                    'shipping_amount' => $ot['shipping'],
                    'total_amount' => $ot['total'],
                    'order_date' => $orderDate,
                    'created_at' => $orderDate,
                    'updated_at' => $orderDate,
                ]
            );

            $order->items()->delete();
            foreach ($ot['items'] as $item) {
                $order->items()->create([
                    'product_name' => $item['name'],
                    'product_sku' => $item['sku'],
                    'quantity' => $item['qty'],
                    'unit_price' => $item['price'],
                    'total_price' => $item['price'] * $item['qty'],
                ]);
            }
        }

        // 4. Seed Quotations (with various statuses)
        $quoteTemplates = [
            [
                'number' => 'QUO-2026-0456',
                'customer' => $seededUsers[0],
                'service' => 'Custom Logo Embroidery (50 Pcs)',
                'status' => 'pending',
                'total' => 412.50,
                'hours_ago' => 1,
            ],
            [
                'number' => 'QUO-2026-0455',
                'customer' => $seededUsers[1],
                'service' => 'Bulk Polo Shirts with Left-Chest Digitizing',
                'status' => 'pending',
                'total' => 1250.00,
                'hours_ago' => 3,
            ],
            [
                'number' => 'QUO-2026-0454',
                'customer' => $seededUsers[2],
                'service' => 'Hooded Sweatshirts Back & Sleeve Embroidery',
                'status' => 'pending',
                'total' => 680.00,
                'hours_ago' => 5,
            ],
            [
                'number' => 'QUO-2026-0453',
                'customer' => $seededUsers[3],
                'service' => 'Cap 3D Puff Embroidery (25 Pcs)',
                'status' => 'quoted',
                'total' => 210.00,
                'hours_ago' => 8,
            ],
            [
                'number' => 'QUO-2026-0452',
                'customer' => $seededUsers[4],
                'service' => 'T-Shirt Direct Screen + Embroidery Hybrid',
                'status' => 'expired',
                'total' => 320.00,
                'hours_ago' => 24,
            ],
            [
                'number' => 'QUO-2026-0451',
                'customer' => $seededUsers[5],
                'service' => 'Corporate Softshell Jackets (15 Pcs)',
                'status' => 'quoted',
                'total' => 950.00,
                'hours_ago' => 36,
            ],
        ];

        foreach ($quoteTemplates as $qt) {
            $createdTime = Carbon::now()->subHours($qt['hours_ago']);
            EcommerceQuotation::updateOrCreate(
                ['quote_number' => $qt['number']],
                [
                    'user_id' => $qt['customer']->id,
                    'customer_name' => $qt['customer']->name,
                    'contact_email' => $qt['customer']->email,
                    'customer_email' => $qt['customer']->email,
                    'company_name' => 'Creative Designs',
                    'status' => $qt['status'],
                    'total_estimated' => $qt['total'],
                    'quote_price' => $qt['total'],
                    'quote_details' => $qt['service'],
                    'valid_until' => now()->addDays(30),
                    'created_at' => $createdTime,
                    'updated_at' => $createdTime,
                ]
            );
        }

        // 5. Seed Disputes
        $disputeTemplates = [
            [
                'number' => 'DSP-2026-012',
                'order_number' => 'ORD-2026-12978',
                'customer' => $seededUsers[0],
                'type' => 'Product not as described',
                'status' => 'Open',
                'description' => 'Thread color is slightly darker navy than mockup pantone.',
                'hours_ago' => 2,
            ],
            [
                'number' => 'DSP-2026-011',
                'order_number' => 'ORD-2026-12970',
                'customer' => $seededUsers[1],
                'type' => 'Wrong item received',
                'status' => 'Under Review',
                'description' => 'Received XL instead of L on 2 pullover items.',
                'hours_ago' => 6,
            ],
            [
                'number' => 'DSP-2026-010',
                'order_number' => 'ORD-2026-12960',
                'customer' => $seededUsers[2],
                'type' => 'Damaged product in transit',
                'status' => 'Open',
                'description' => 'Box arrived crushed by carrier, 1 jacket has torn seam.',
                'hours_ago' => 18,
            ],
            [
                'number' => 'DSP-2026-009',
                'order_number' => 'ORD-2026-12950',
                'customer' => $seededUsers[3],
                'type' => 'Missing items',
                'status' => 'Resolved',
                'description' => 'Replacement snapback cap shipped via tracking 1Z9992019.',
                'hours_ago' => 48,
            ],
        ];

        foreach ($disputeTemplates as $dt) {
            $dTime = Carbon::now()->subHours($dt['hours_ago']);
            EcommerceDispute::updateOrCreate(
                ['dispute_number' => $dt['number']],
                [
                    'user_id' => $dt['customer']->id,
                    'order_number' => $dt['order_number'],
                    'customer_name' => $dt['customer']->name,
                    'email' => $dt['customer']->email,
                    'type' => $dt['type'],
                    'status' => $dt['status'],
                    'description' => $dt['description'],
                    'created_at' => $dTime,
                    'updated_at' => $dTime,
                ]
            );
        }

        // 6. Seed Support Tickets
        $ticketTemplates = [
            [
                'number' => 'TKT-2026-0589',
                'customer' => $seededUsers[0],
                'subject' => 'Need help digitizing vector artwork file',
                'status' => 'open',
                'priority' => 'high',
                'hours_ago' => 1,
            ],
            [
                'number' => 'TKT-2026-0588',
                'customer' => $seededUsers[1],
                'subject' => 'Change delivery address before FedEx dispatch',
                'status' => 'in_progress',
                'priority' => 'urgent',
                'hours_ago' => 3,
            ],
            [
                'number' => 'TKT-2026-0587',
                'customer' => $seededUsers[2],
                'subject' => 'Custom embroidery metallic gold thread inquiry',
                'status' => 'in_progress',
                'priority' => 'medium',
                'hours_ago' => 7,
            ],
            [
                'number' => 'TKT-2026-0586',
                'customer' => $seededUsers[3],
                'subject' => 'Order proof approval turnaround time query',
                'status' => 'closed',
                'priority' => 'low',
                'hours_ago' => 28,
            ],
        ];

        foreach ($ticketTemplates as $tt) {
            $tTime = Carbon::now()->subHours($tt['hours_ago']);
            EcommerceTicket::updateOrCreate(
                ['ticket_number' => $tt['number']],
                [
                    'user_id' => $tt['customer']->id,
                    'customer_name' => $tt['customer']->name,
                    'contact_email' => $tt['customer']->email,
                    'subject' => $tt['subject'],
                    'status' => $tt['status'],
                    'priority' => $tt['priority'],
                    'message' => 'Customer submitted ticket: ' . $tt['subject'],
                    'created_at' => $tTime,
                    'updated_at' => $tTime,
                ]
            );
        }

        // 7. Seed Conversations & Messages
        $convTemplates = [
            [
                'customer' => $seededUsers[7],
                'subject' => 'Custom design file mockup and sizing review',
                'status' => 'active',
                'messages' => [
                    ['sender_type' => 'customer', 'text' => 'Can I get a digital 3D mockup before production starts?'],
                    ['sender_type' => 'admin', 'text' => 'Sure! Our team will provide a 3D digitization preview within 2 hours.'],
                ],
                'minutes_ago' => 5,
            ],
            [
                'customer' => $seededUsers[8],
                'subject' => 'Bulk polo shirt order discount for 50 pieces',
                'status' => 'active',
                'messages' => [
                    ['sender_type' => 'customer', 'text' => 'Looking to place 50 embroidered polos for our team.'],
                ],
                'minutes_ago' => 32,
            ],
            [
                'customer' => $seededUsers[5],
                'subject' => 'Embroidery placement on left chest vs right sleeve',
                'status' => 'active',
                'messages' => [
                    ['sender_type' => 'customer', 'text' => 'We uploaded both vector files for your review.'],
                ],
                'minutes_ago' => 60,
            ],
            [
                'customer' => $seededUsers[6],
                'subject' => 'Shipping options for express turnaround to California',
                'status' => 'closed',
                'messages' => [
                    ['sender_type' => 'customer', 'text' => 'What is the fastest 2-day delivery rate for bulk jackets?'],
                ],
                'minutes_ago' => 180,
            ],
        ];

        foreach ($convTemplates as $ct) {
            $cTime = Carbon::now()->subMinutes($ct['minutes_ago']);
            $conv = EcommerceConversation::firstOrCreate(
                [
                    'user_id' => $ct['customer']->id,
                    'subject' => $ct['subject'],
                ],
                [
                    'status' => $ct['status'],
                    'last_message_at' => $cTime,
                    'created_at' => $cTime,
                    'updated_at' => $cTime,
                ]
            );

            foreach ($ct['messages'] as $m) {
                EcommerceConversationMessage::create([
                    'conversation_id' => $conv->id,
                    'sender_type' => $m['sender_type'],
                    'message' => $m['text'],
                    'created_at' => $cTime,
                    'updated_at' => $cTime,
                ]);
            }
        }

        // 8. Seed Gift Cards
        $giftCardTemplates = [
            ['code' => 'MCG-1008', 'initial' => 100.00, 'current' => 100.00, 'type' => 'physical', 'hours_ago' => 2],
            ['code' => 'MCG-0750', 'initial' => 75.00, 'current' => 75.00, 'type' => 'digital', 'hours_ago' => 3],
            ['code' => 'MCG-0500', 'initial' => 50.00, 'current' => 50.00, 'type' => 'digital', 'hours_ago' => 5],
            ['code' => 'MCG-0250', 'initial' => 25.00, 'current' => 25.00, 'type' => 'physical', 'hours_ago' => 24],
        ];

        foreach ($giftCardTemplates as $gc) {
            $gcTime = Carbon::now()->subHours($gc['hours_ago']);
            EcommerceGiftCard::updateOrCreate(
                ['code' => $gc['code']],
                [
                    'recipient_name' => 'Valued Customer',
                    'recipient_email' => 'gift@example.com',
                    'initial_balance' => $gc['initial'],
                    'current_balance' => $gc['current'],
                    'status' => 'active',
                    'delivery_type' => $gc['type'],
                    'currency' => 'USD',
                    'created_at' => $gcTime,
                    'updated_at' => $gcTime,
                ]
            );
        }

        // 9. Seed Affiliates & Earnings
        $affiliateTemplates = [
            ['customer' => $seededUsers[0], 'code' => 'AFF-MICHAEL20', 'earnings' => 1240.00, 'referrals' => 28],
            ['customer' => $seededUsers[1], 'code' => 'AFF-SARAHPRO', 'earnings' => 2240.00, 'referrals' => 45],
            ['customer' => $seededUsers[2], 'code' => 'AFF-DAVIDSUMMIT', 'earnings' => 1245.00, 'referrals' => 19],
        ];

        foreach ($affiliateTemplates as $at) {
            EcommerceAffiliate::updateOrCreate(
                ['affiliate_code' => $at['code']],
                [
                    'user_id' => $at['customer']->id,
                    'total_earnings' => $at['earnings'],
                    'total_referrals' => $at['referrals'],
                    'status' => 'active',
                    'created_at' => now()->subDays(30),
                ]
            );
        }

        // 10. Seed Subscription Plans & Memberships
        $plans = [
            ['name' => 'Mecarvi Gold', 'price' => 9.99, 'billing_cycle' => 'monthly', 'features' => ['10% off all embroidery', 'Free shipping on orders over $50', 'Priority digitization']],
            ['name' => 'Mecarvi Platinum', 'price' => 18.99, 'billing_cycle' => 'monthly', 'features' => ['20% off all embroidery', 'Free 2-day FedEx shipping', 'Free 3D puff digitization', 'Dedicated account manager']],
            ['name' => 'Mecarvi Business Essentials', 'price' => 29.99, 'billing_cycle' => 'monthly', 'features' => ['30% wholesale discount', 'Unlimited free proofs', 'Net-30 invoicing eligibility', 'Priority queue']],
            ['name' => 'Business Sapphire Tier', 'price' => 49.99, 'billing_cycle' => 'monthly', 'features' => ['35% bulk discount', 'Custom Pantone thread matching', 'Dedicated digitizer']],
            ['name' => 'Business Diamond Elite', 'price' => 79.99, 'billing_cycle' => 'monthly', 'features' => ['40% enterprise discount', 'Same-day rush turnaround', 'White-label shipping']],
        ];

        foreach ($plans as $p) {
            EcommerceSubscriptionPlan::updateOrCreate(
                ['name' => $p['name']],
                [
                    'price' => $p['price'],
                    'billing_cycle' => $p['billing_cycle'],
                    'features' => $p['features'],
                    'status' => 'active',
                ]
            );
        }

        $this->command->info('✓ Master dashboard data seeded successfully!');
    }
}
