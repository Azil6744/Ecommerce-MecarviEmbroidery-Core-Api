<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\EcommerceProductQuestion;
use App\Models\EcommerceProductQuestionReply;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ProductQuestionsSeeder extends Seeder
{
    public function run(): void
    {
        $apparelCat = Category::firstOrCreate(['name' => 'Apparel'], ['slug' => 'apparel', 'is_active' => true]);
        $headwearCat = Category::firstOrCreate(['name' => 'Headwear'], ['slug' => 'headwear', 'is_active' => true]);
        $bagsCat = Category::firstOrCreate(['name' => 'Bags & Accessories'], ['slug' => 'bags-accessories', 'is_active' => true]);
        $workwearCat = Category::firstOrCreate(['name' => 'Workwear'], ['slug' => 'workwear', 'is_active' => true]);

        $seedData = [
            [
                'product' => [
                    'name' => 'Premium Logo Embroidered Hoodie',
                    'sku' => 'DEMO-HOODIE-001',
                    'category_id' => $apparelCat->id,
                    'price' => 54.00,
                    'is_active' => true,
                    'images' => ['/images/products/hoodie_green.jpg'],
                ],
                'question' => 'Can I get this hoodie in a 3XL? Also, do you offer custom color thread options?',
                'customer_name' => 'Sarah Johnson',
                'customer_email' => 'sarah.johnson@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-10 10:24:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Hi Sarah! Yes, 3XL is available upon request. We also offer over 40+ Madeira thread shades for customized embroidery.',
                        'created_at' => Carbon::parse('2026-09-10 10:45:00'),
                    ],
                    [
                        'name' => 'Sarah Johnson',
                        'role' => 'customer',
                        'content' => 'Awesome! How long will custom thread colors take for production?',
                        'created_at' => Carbon::parse('2026-09-10 11:10:00'),
                    ],
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Standard turnaround is 3-5 business days from digital proof approval.',
                        'created_at' => Carbon::parse('2026-09-10 11:30:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Classic Polo Embroidered Shirt',
                    'sku' => 'DEMO-POLO-002',
                    'category_id' => $apparelCat->id,
                    'price' => 32.00,
                    'is_active' => true,
                    'images' => ['/images/products/polo_black.png'],
                ],
                'question' => 'What is the minimum order quantity for company logos? Do you offer bulk discounts?',
                'customer_name' => 'Michael Baptiste',
                'customer_email' => 'm.baptiste@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-10 09:46:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Hi Michael! Our minimum order quantity is only 1 piece, but we provide tier discounts starting at 12+ units (10% off) and 50+ units (20% off).',
                        'created_at' => Carbon::parse('2026-09-10 10:15:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Embroidered Logo Cap',
                    'sku' => 'DEMO-CAP-003',
                    'category_id' => $headwearCat->id,
                    'price' => 22.00,
                    'is_active' => true,
                    'images' => ['/images/products/cap_black.jpg'],
                ],
                'question' => 'Can I see a sample of the logo placement before I place my order?',
                'customer_name' => 'Tanya Roberts',
                'customer_email' => 'tanya.roberts@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-09 16:32:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Hello Tanya! Yes, our design team provides a 3D digital proof within 24 hours of order placement before stitching begins.',
                        'created_at' => Carbon::parse('2026-09-09 17:00:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Embroidered Tote Bag',
                    'sku' => 'DEMO-BAG-005',
                    'category_id' => $bagsCat->id,
                    'price' => 18.00,
                    'is_active' => true,
                    'images' => ['/images/products/tote_natural.jpg'],
                ],
                'question' => 'Is the tote bag canvas or polyester? And what are the care instructions?',
                'customer_name' => 'Kevin Charles',
                'customer_email' => 'k.charles@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-09 14:18:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'It is made of 100% heavy-duty 12oz natural cotton canvas. Hand wash or gentle machine wash cold and air dry for best durability.',
                        'created_at' => Carbon::parse('2026-09-09 14:45:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Custom Embroidered Jacket',
                    'sku' => 'DEMO-JACKET-006',
                    'category_id' => $workwearCat->id,
                    'price' => 75.00,
                    'is_active' => true,
                    'images' => ['/images/products/jacket_black.png'],
                ],
                'question' => 'Do you offer water-resistant jackets? And can I add both a logo and a name?',
                'customer_name' => 'Nicole Adams',
                'customer_email' => 'nicole.adams@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-09 11:05:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Yes, this jacket has a DWR water-resistant outer shell. You can add your company logo on the left chest and personalization/name on the right chest.',
                        'created_at' => Carbon::parse('2026-09-09 11:30:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Custom T-Shirt',
                    'sku' => 'DEMO-TSHIRT-004',
                    'category_id' => $apparelCat->id,
                    'price' => 24.00,
                    'is_active' => true,
                    'images' => ['/images/products/tshirt_white.png'],
                ],
                'question' => 'Can you show me the font options available for the text?',
                'customer_name' => 'Daniel Williams',
                'customer_email' => 'danielw@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-08 15:21:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'We support over 50+ embroidery typography styles including script, block, serif, and athletic collegiate fonts.',
                        'created_at' => Carbon::parse('2026-09-08 15:50:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => "Women's Embroidered Polo",
                    'sku' => 'DEMO-W-POLO-007',
                    'category_id' => $apparelCat->id,
                    'price' => 34.00,
                    'is_active' => true,
                    'images' => ['/images/products/polo_green.jpg'],
                ],
                'question' => 'Do you have this polo in women\'s sizes? Is there a size chart available?',
                'customer_name' => 'Latoya Green',
                'customer_email' => 'latoya.green@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-08 13:14:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Yes, sizes range from XS to 2XL with a contoured feminine silhouette. Check our sizing guide tab on the product page.',
                        'created_at' => Carbon::parse('2026-09-08 13:40:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Custom Text Hoodie',
                    'sku' => 'DEMO-HOODIE-008',
                    'category_id' => $apparelCat->id,
                    'price' => 52.00,
                    'is_active' => true,
                    'images' => ['/images/products/hoodie_black.jpg'],
                ],
                'question' => 'Can I add different text on the front and back? What is the turnaround time?',
                'customer_name' => 'R. Martin',
                'customer_email' => 'rmartin@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-07 18:27:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Yes, dual-placement embroidery (front chest + full back) is supported. Production takes 3-4 business days.',
                        'created_at' => Carbon::parse('2026-09-07 19:10:00'),
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Embroidered Backpack',
                    'sku' => 'DEMO-BAG-009',
                    'category_id' => $bagsCat->id,
                    'price' => 45.00,
                    'is_active' => true,
                    'images' => ['/images/products/backpack_black.jpg'],
                ],
                'question' => 'Is this backpack suitable for school use? Can I add a name and logo?',
                'customer_name' => 'Jason Clarke',
                'customer_email' => 'jason.clarke@email.com',
                'status' => 'answered',
                'created_at' => Carbon::parse('2026-09-07 12:15:00'),
                'replies' => [
                    [
                        'name' => 'Admin Support',
                        'role' => 'admin',
                        'content' => 'Yes, it features a padded 15.6-inch laptop compartment and reinforced water-resistant fabric. Front pocket embroidery is perfect for school logos and student names.',
                        'created_at' => Carbon::parse('2026-09-07 12:45:00'),
                    ],
                ],
            ],
        ];

        foreach ($seedData as $data) {
            $product = Product::firstOrCreate(
                ['sku' => $data['product']['sku']],
                [
                    'name' => $data['product']['name'],
                    'category_id' => $data['product']['category_id'],
                    'price' => $data['product']['price'],
                    'is_active' => true,
                    'images' => $data['product']['images'],
                    'description' => 'Custom embroidery product',
                ]
            );

            // Create question if not exists
            $question = EcommerceProductQuestion::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'customer_email' => $data['customer_email'],
                    'question' => $data['question'],
                ],
                [
                    'customer_name' => $data['customer_name'],
                    'status' => $data['status'],
                    'created_at' => $data['created_at'],
                    'updated_at' => $data['created_at'],
                ]
            );

            // Add replies
            if (isset($data['replies']) && is_array($data['replies'])) {
                foreach ($data['replies'] as $replyData) {
                    EcommerceProductQuestionReply::firstOrCreate(
                        [
                            'product_question_id' => $question->id,
                            'content' => $replyData['content'],
                        ],
                        [
                            'name' => $replyData['name'],
                            'role' => $replyData['role'],
                            'helpful_count' => 0,
                            'created_at' => $replyData['created_at'],
                            'updated_at' => $replyData['created_at'],
                        ]
                    );
                }
            }
        }
    }
}
