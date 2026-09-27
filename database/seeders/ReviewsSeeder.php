<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EcommerceReview;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;

class ReviewsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = Product::where('is_active', true)->get();
        if ($products->isEmpty()) {
            // Create fallback products
            $hoodie = Product::firstOrCreate(['sku' => 'DEMO-HOODIE-001'], [
                'name' => 'Premium Logo Embroidered Hoodie',
                'price' => 49.99,
                'is_active' => true,
            ]);
            $polo = Product::firstOrCreate(['sku' => 'DEMO-POLO-002'], [
                'name' => 'Classic Polo Embroidered Shirt',
                'price' => 29.99,
                'is_active' => true,
            ]);
            $cap = Product::firstOrCreate(['sku' => 'DEMO-CAP-003'], [
                'name' => 'Embroidered Logo Cap',
                'price' => 19.99,
                'is_active' => true,
            ]);
            $tote = Product::firstOrCreate(['sku' => 'DEMO-BAG-005'], [
                'name' => 'Embroidered Tote Bag',
                'price' => 24.99,
                'is_active' => true,
            ]);
            $backpack = Product::firstOrCreate(['sku' => 'DEMO-BAG-009'], [
                'name' => 'Embroidered Backpack',
                'price' => 59.99,
                'is_active' => true,
            ]);
        } else {
            $hoodie = $products->firstWhere('sku', 'DEMO-HOODIE-001') ?? $products->first();
            $polo = $products->firstWhere('sku', 'DEMO-POLO-002') ?? $products->skip(1)->first() ?? $hoodie;
            $cap = $products->firstWhere('sku', 'DEMO-CAP-003') ?? $products->skip(2)->first() ?? $hoodie;
            $tote = $products->firstWhere('sku', 'DEMO-BAG-005') ?? $products->skip(3)->first() ?? $hoodie;
            $backpack = $products->firstWhere('sku', 'DEMO-BAG-009') ?? $products->skip(4)->first() ?? $hoodie;
        }

        $users = User::all();
        $sampleUser1 = $users->first();
        $sampleUser2 = $users->skip(1)->first() ?? $sampleUser1;
        $sampleUser3 = $users->skip(2)->first() ?? $sampleUser1;

        $reviews = [
            [
                'product_id' => $hoodie->id,
                'user_id' => $sampleUser1?->id,
                'customer_name' => 'Sophia Martinez',
                'rating' => 5,
                'title' => 'Exceptional Embroidery Quality & Soft Fabric!',
                'comment' => 'Ordered 24 hoodies for our tech company team. The thread precision on our complex logo is unmatched. Sizing fits true to size and the fleece inside is super soft!',
                'status' => 'approved',
                'created_at' => Carbon::parse('2026-09-10 14:32:00'),
            ],
            [
                'product_id' => $polo->id,
                'user_id' => $sampleUser2?->id,
                'customer_name' => 'David Richardson',
                'rating' => 5,
                'title' => 'Crisp Logo Stitching for Corporate Event',
                'comment' => 'We received countless compliments during our annual expo. Turnaround was even faster than quoted. Will definitely reorder for our next quarterly summit.',
                'status' => 'approved',
                'created_at' => Carbon::parse('2026-09-10 11:15:00'),
            ],
            [
                'product_id' => $cap->id,
                'user_id' => $sampleUser3?->id,
                'customer_name' => 'Marcus Vance',
                'rating' => 5,
                'title' => '3D Puff Embroidery Looks Ultra Premium',
                'comment' => 'The structured cap fits great with an adjustable brass buckle. The 3D embroidery detail has serious depth and vibrancy. 10/10 recommendation!',
                'status' => 'approved',
                'created_at' => Carbon::parse('2026-09-09 16:45:00'),
            ],
            [
                'product_id' => $tote->id,
                'user_id' => $sampleUser1?->id,
                'customer_name' => 'Emily Chang',
                'rating' => 4,
                'title' => 'Heavy Duty Canvas & Crisp Design',
                'comment' => 'Durable material with reinforced straps. The embroidery is vivid. Just took 1 extra day for courier delivery, but the product itself is fantastic.',
                'status' => 'pending',
                'created_at' => Carbon::parse('2026-09-09 09:20:00'),
            ],
            [
                'product_id' => $backpack->id,
                'user_id' => $sampleUser2?->id,
                'customer_name' => 'Lucas Wright',
                'rating' => 5,
                'title' => 'Perfect for University & Travel',
                'comment' => 'Spacious laptop sleeve and the custom monogram embroidery looks sleek. The water-resistant finish handled heavy rain with no problem.',
                'status' => 'approved',
                'created_at' => Carbon::parse('2026-09-08 17:10:00'),
            ],
            [
                'product_id' => $hoodie->id,
                'user_id' => $sampleUser3?->id,
                'customer_name' => 'Jessica Taylor',
                'rating' => 5,
                'title' => 'Loved the custom Madeira thread match!',
                'comment' => 'Our brand colors are notoriously tricky to match in thread, but Mecarvi nailed the Pantone exact match. High quality through and through.',
                'status' => 'approved',
                'created_at' => Carbon::parse('2026-09-08 13:05:00'),
            ],
            [
                'product_id' => $polo->id,
                'user_id' => $sampleUser1?->id,
                'customer_name' => 'Brandon Cole',
                'rating' => 4,
                'title' => 'Breathable Pique Cotton',
                'comment' => 'High quality fabric that holds up well after multiple industrial washes. The chest logo maintains its sharp shape without puckering.',
                'status' => 'pending',
                'created_at' => Carbon::parse('2026-09-07 15:40:00'),
            ],
            [
                'product_id' => $cap->id,
                'user_id' => $sampleUser2?->id,
                'customer_name' => 'Amber Lewis',
                'rating' => 5,
                'title' => 'Top Tier Service and Stitching',
                'comment' => 'Our charity run team loved these caps! Great sun protection and the color contrast on the embroidery is crystal clear.',
                'status' => 'approved',
                'created_at' => Carbon::parse('2026-09-07 10:18:00'),
            ],
            [
                'product_id' => $backpack->id,
                'user_id' => $sampleUser3?->id,
                'customer_name' => 'Nathan Drake',
                'rating' => 3,
                'title' => 'Good bag, zipper was a bit stiff at first',
                'comment' => 'The embroidery looks phenomenal and bag is solid. The main compartment zipper was slightly stiff initially but smoothed out after a week.',
                'status' => 'pending',
                'created_at' => Carbon::parse('2026-09-06 18:30:00'),
            ],
            [
                'product_id' => $hoodie->id,
                'user_id' => $sampleUser1?->id,
                'customer_name' => 'Chloe Frazer',
                'rating' => 5,
                'title' => 'Great weight for winter seasons',
                'comment' => 'Thick fleece lining with dense embroidery. Awaiting approval for corporate discount code verification.',
                'status' => 'pending',
                'created_at' => Carbon::parse('2026-09-06 14:12:00'),
            ],
            [
                'product_id' => $polo->id,
                'user_id' => $sampleUser2?->id,
                'customer_name' => 'Alexander Hayes',
                'rating' => 5,
                'title' => 'Excellent fit and comfortable collar',
                'comment' => 'Staff members love wearing them on client site visits. Threading is very neat on the back yoke.',
                'status' => 'pending',
                'created_at' => Carbon::parse('2026-09-05 11:20:00'),
            ],
            [
                'product_id' => $cap->id,
                'user_id' => $sampleUser3?->id,
                'customer_name' => 'Victoria Bennett',
                'rating' => 4,
                'title' => 'Sharp embroidery on structured crown',
                'comment' => 'Looks very classy. Would love to see more colorway options for the bill.',
                'status' => 'pending',
                'created_at' => Carbon::parse('2026-09-04 16:45:00'),
            ],
            [
                'product_id' => $tote->id,
                'user_id' => $sampleUser1?->id,
                'customer_name' => 'Oliver Queen',
                'rating' => 2,
                'title' => 'Color shade slightly different than mockup',
                'comment' => 'The green embroidery was lighter than our hex code mockup. Hoping for resolution.',
                'status' => 'rejected',
                'created_at' => Carbon::parse('2026-09-03 10:15:00'),
            ],
        ];

        foreach ($reviews as $rev) {
            EcommerceReview::updateOrCreate(
                [
                    'product_id' => $rev['product_id'],
                    'customer_name' => $rev['customer_name'],
                    'title' => $rev['title'],
                ],
                [
                    'user_id' => $rev['user_id'] ?? null,
                    'rating' => $rev['rating'],
                    'comment' => $rev['comment'],
                    'status' => $rev['status'],
                    'created_at' => $rev['created_at'],
                    'updated_at' => $rev['created_at'],
                ]
            );
        }
    }
}

