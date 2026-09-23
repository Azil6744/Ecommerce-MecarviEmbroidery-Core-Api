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
        $uniformsCat = Category::firstOrCreate(['name' => 'Uniforms'], ['slug' => 'uniforms', 'is_active' => true]);
        $workwearCat = Category::firstOrCreate(['name' => 'Workwear'], ['slug' => 'workwear', 'is_active' => true]);
        $promoCat = Category::firstOrCreate(['name' => 'Promotional Items'], ['slug' => 'promotional-items', 'is_active' => true]);
        $towelsCat = Category::firstOrCreate(['name' => 'Towels & Blankets'], ['slug' => 'towels-blankets', 'is_active' => true]);
        $giftsCat = Category::firstOrCreate(['name' => 'Corporate Gifts'], ['slug' => 'corporate-gifts', 'is_active' => true]);
        $otherCat = Category::firstOrCreate(['name' => 'Other Products'], ['slug' => 'other-products', 'is_active' => true]);

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
            // Additional products across categories
            [
                'product' => [
                    'name' => 'Custom Knit Beanie',
                    'sku' => 'DEMO-BEAN-007',
                    'category_id' => $headwearCat->id,
                    'price' => 19.00,
                    'is_active' => true,
                ],
                'question' => 'Is this beanie stretchable for one-size-fits-all?',
                'customer_name' => 'David Lee',
                'customer_email' => 'david.lee@email.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-11 12:00:00'),
            ],
            [
                'product' => [
                    'name' => 'Classic Snapback Cap',
                    'sku' => 'DEMO-SNAP-008',
                    'category_id' => $headwearCat->id,
                    'price' => 25.00,
                    'is_active' => true,
                ],
                'question' => 'Can we embroider 3D puff embroidery on this snapback?',
                'customer_name' => 'Alex Turner',
                'customer_email' => 'alex.t@email.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-11 14:30:00'),
            ],
            [
                'product' => [
                    'name' => 'Corporate Button-Down Shirt',
                    'sku' => 'DEMO-UNIF-012',
                    'category_id' => $uniformsCat->id,
                    'price' => 39.00,
                    'is_active' => true,
                ],
                'question' => 'Are these wrinkle-resistant for daily corporate office wear?',
                'customer_name' => 'Amanda Miller',
                'customer_email' => 'amanda.m@email.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-12 09:15:00'),
            ],
            [
                'product' => [
                    'name' => 'Medical Scrub Top',
                    'sku' => 'DEMO-SCRB-013',
                    'category_id' => $uniformsCat->id,
                    'price' => 28.00,
                    'is_active' => true,
                ],
                'question' => 'Can we add hospital department names under the doctor logo?',
                'customer_name' => 'Dr. Robert Chen',
                'customer_email' => 'dr.chen@clinic.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-12 11:20:00'),
            ],
            [
                'product' => [
                    'name' => 'Hi-Vis Safety Work Vest',
                    'sku' => 'DEMO-VEST-015',
                    'category_id' => $workwearCat->id,
                    'price' => 26.00,
                    'is_active' => true,
                ],
                'question' => 'Does this vest meet ANSI/ISEA Class 2 safety standards?',
                'customer_name' => 'Mark Jenkins',
                'customer_email' => 'mjenkins@buildcorp.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-13 08:45:00'),
            ],
            [
                'product' => [
                    'name' => 'Custom Embroidered Keychains Set',
                    'sku' => 'DEMO-KEY-017',
                    'category_id' => $promoCat->id,
                    'price' => 4.50,
                    'is_active' => true,
                ],
                'question' => 'Can both sides have different text or color border stitching?',
                'customer_name' => 'Lisa Ray',
                'customer_email' => 'lisaray@events.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-13 14:10:00'),
            ],
            [
                'product' => [
                    'name' => 'Branded Embroidered Patches',
                    'sku' => 'DEMO-PTCH-018',
                    'category_id' => $promoCat->id,
                    'price' => 3.20,
                    'is_active' => true,
                ],
                'question' => 'Do you provide iron-on and velcro backing options?',
                'customer_name' => 'Chris Evans',
                'customer_email' => 'chrise@club.org',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-14 10:00:00'),
            ],
            [
                'product' => [
                    'name' => 'Plush Embroidered Bath Towel',
                    'sku' => 'DEMO-TWL-020',
                    'category_id' => $towelsCat->id,
                    'price' => 29.00,
                    'is_active' => true,
                ],
                'question' => 'What is the GSM weight of this towel?',
                'customer_name' => 'Hotel Spa Admin',
                'customer_email' => 'admin@luxuryresort.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-14 16:30:00'),
            ],
            [
                'product' => [
                    'name' => 'Fleece Stadium Blanket',
                    'sku' => 'DEMO-BLNK-022',
                    'category_id' => $towelsCat->id,
                    'price' => 35.00,
                    'is_active' => true,
                ],
                'question' => 'How large is the embroidery area in the corner?',
                'customer_name' => 'Booster Club',
                'customer_email' => 'boosters@highschool.edu',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-15 09:20:00'),
            ],
            [
                'product' => [
                    'name' => 'Executive Embroidered Gift Set',
                    'sku' => 'DEMO-GIFT-023',
                    'category_id' => $giftsCat->id,
                    'price' => 65.00,
                    'is_active' => true,
                ],
                'question' => 'Does this gift set come in a custom branded gift box?',
                'customer_name' => 'Elena Rostova',
                'customer_email' => 'elena@corporatehr.com',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-15 13:40:00'),
            ],
            [
                'product' => [
                    'name' => 'Custom Digitized Embroidery Patch',
                    'sku' => 'DEMO-PTCH-026',
                    'category_id' => $otherCat->id,
                    'price' => 15.00,
                    'is_active' => true,
                ],
                'question' => 'Can you digitize high-complexity vector logos with small details?',
                'customer_name' => 'Samir Patel',
                'customer_email' => 'samir@designstudio.io',
                'status' => 'unanswered',
                'created_at' => Carbon::parse('2026-09-16 11:00:00'),
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
                    'images' => $data['product']['images'] ?? null,
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
