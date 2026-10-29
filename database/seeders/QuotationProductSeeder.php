<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class QuotationProductSeeder extends Seeder
{
    public function run(): void
    {
        $categoryMap = [
            'Apparel' => [
                'slug' => 'apparel',
                'subcategories' => ['Polo Shirts', 'Hoodies', 'T-Shirts', 'Jackets'],
            ],
            'Headwear' => [
                'slug' => 'headwear',
                'subcategories' => ['Snapback Hats', 'Dad Hats', 'Beanies', 'Trucker Hats'],
            ],
            'Bags & Backpacks' => [
                'slug' => 'bags-and-backpacks',
                'subcategories' => ['Tote Bags', 'Duffel Bags', 'Backpacks'],
            ],
            'Patches' => [
                'slug' => 'patches',
                'subcategories' => ['Embroidered Patches', 'Leather Patches', 'Velcro Patches', 'Iron-On Patches'],
            ],
            'Accessories' => [
                'slug' => 'accessories',
                'subcategories' => ['Tumblers & Drinkware', 'Towels', 'Keychains & Lanyards'],
            ],
            'Safety Wear' => [
                'slug' => 'safety-wear',
                'subcategories' => ['Safety Vests', 'Hi-Vis Jackets', 'Work Shirts'],
            ],
            'Uniforms' => [
                'slug' => 'uniforms',
                'subcategories' => ['Corporate Uniforms', 'Athletic Teamwear', 'Healthcare Uniforms'],
            ],
            'Others' => [
                'slug' => 'others',
                'subcategories' => ['Custom Orders', 'Event Merchandise', 'Gift Sets'],
            ],
        ];

        // Resolve or create parent & child categories
        $resolvedCategories = [];
        foreach ($categoryMap as $parentName => $config) {
            $parent = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($parentName)])->first();
            if (! $parent) {
                $parent = Category::create([
                    'name' => $parentName,
                    'slug' => $config['slug'],
                    'parent_id' => null,
                    'is_active' => true,
                    'description' => "{$parentName} category for quotation products.",
                ]);
            }

            foreach ($config['subcategories'] as $subName) {
                $child = Category::where('parent_id', $parent->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($subName)])
                    ->first();

                if (! $child) {
                    $child = Category::create([
                        'name' => $subName,
                        'slug' => Str::slug($parentName . '-' . $subName),
                        'parent_id' => $parent->id,
                        'is_active' => true,
                        'description' => "{$subName} under {$parentName}.",
                    ]);
                }

                $resolvedCategories["{$parentName}::{$subName}"] = $child;
            }
        }

        $items = [
            // 1. Apparel - Polo
            [
                'parent' => 'Apparel',
                'sub' => 'Polo Shirts',
                'name' => 'Custom Embroidered Corporate Polo Shirt',
                'sku' => 'QUO-POLO-001',
                'price' => 24.00,
                'stock' => 250,
                'brand' => 'Mecarvi Basics',
                'weight' => 0.5,
                'dimensions' => json_encode(['length' => 10, 'width' => 8, 'height' => 2]),
                'turnaround' => '3 – 5 business days',
                'images' => [
                    '/assets/images/reported-products/polo.jpg',
                    '/assets/images/search/var_polo_1.png',
                    '/assets/images/search/var_polo_2.png',
                    '/assets/images/search/var_polo_3.png',
                ],
                'short_description' => 'High quality embroidered polo shirt with customizable design. Perfect for businesses, teams and corporate events.',
                'description' => 'High quality embroidered polo shirt crafted with 100% breathable pique cotton, reinforced double-needle collar, and precision logo stitching. Built for daily corporate wear and hospitality staff.',
            ],
            // 2. Apparel - Hoodie
            [
                'parent' => 'Apparel',
                'sub' => 'Hoodies',
                'name' => 'Premium Heavyweight Fleece Custom Hoodie Quote',
                'sku' => 'QUO-HOOD-002',
                'price' => 38.00,
                'stock' => 180,
                'brand' => 'Mecarvi Fleece',
                'weight' => 1.2,
                'dimensions' => json_encode(['length' => 12, 'width' => 10, 'height' => 3]),
                'turnaround' => '4 – 6 business days',
                'images' => [
                    '/assets/images/reported-products/hoodie.jpg',
                    '/assets/images/search/var_hoodie_1.png',
                    '/assets/images/search/var_hoodie_2.png',
                    '/assets/images/search/var_hoodie_3.png',
                ],
                'short_description' => 'Plush 380 GSM fleece pullover hoodie with double-needle hood and pouch pocket. Embroidered with dense poly thread.',
                'description' => 'Heavyweight 380 GSM fleece pullover hoodie designed for ultra-crisp custom logo embroidery and superior warmth. Features reinforced ribbed cuffs and double-lined hood.',
            ],
            // 3. Headwear - Snapback
            [
                'parent' => 'Headwear',
                'sub' => 'Snapback Hats',
                'name' => 'Classic 3D Puff Embroidery Snapback Hat',
                'sku' => 'QUO-SNAP-003',
                'price' => 18.00,
                'stock' => 300,
                'brand' => 'Mecarvi Headwear',
                'weight' => 0.3,
                'dimensions' => json_encode(['length' => 8, 'width' => 8, 'height' => 5]),
                'turnaround' => '2 – 4 business days',
                'images' => [
                    '/assets/images/reported-products/cap.jpg',
                    '/assets/images/search/var_snapback_1.png',
                    '/assets/images/search/var_snapback_2.png',
                    '/assets/images/search/var_snapback_3.png',
                ],
                'short_description' => 'Structured 6-panel snapback cap with flat bill and contrast underbill. Custom 3D puff embroidery available.',
                'description' => 'Structured 6-panel pro crown snapback with flat brim and classic green underbill. Premium high-density 3D puff embroidery available for team crests and corporate branding.',
            ],
            // 4. Headwear - Dad Hat
            [
                'parent' => 'Headwear',
                'sub' => 'Dad Hats',
                'name' => 'Vintage Washed Low-Profile Custom Dad Cap',
                'sku' => 'QUO-DAD-004',
                'price' => 16.50,
                'stock' => 85,
                'brand' => 'Mecarvi Classics',
                'weight' => 0.3,
                'dimensions' => json_encode(['length' => 8, 'width' => 8, 'height' => 4]),
                'turnaround' => '2 – 4 business days',
                'images' => [
                    '/assets/images/reported-products/hat.jpg',
                    '/assets/images/search/var_trucker_1.png',
                    '/assets/images/search/var_trucker_2.png',
                    '/assets/images/search/var_trucker_3.png',
                ],
                'short_description' => 'Unstructured 6-panel low profile cotton twill dad cap with antique brass buckle closure. Minimalist and vintage style.',
                'description' => 'Unstructured relaxed fit cotton twill dad cap with curved bill and antique brass buckle slider. Designed for refined micro-embroidery and casual corporate lifestyle merchandise.',
            ],
            // 5. Bags & Backpacks - Tote Bag
            [
                'parent' => 'Bags & Backpacks',
                'sub' => 'Tote Bags',
                'name' => 'Heavyweight Cotton Canvas Trade Show Tote Bag',
                'sku' => 'QUO-TOTE-005',
                'price' => 12.00,
                'stock' => 500,
                'brand' => 'Mecarvi Eco',
                'weight' => 0.4,
                'dimensions' => json_encode(['length' => 15, 'width' => 16, 'height' => 4]),
                'turnaround' => '3 – 5 business days',
                'images' => [
                    '/assets/images/reported-products/backpack.jpg',
                    '/assets/images/search/var_duffle_1.png',
                    '/assets/images/search/var_duffle_2.png',
                    '/images/quotation-products/qp_totebag.png',
                ],
                'short_description' => 'Heavyweight 12oz natural cotton canvas tote with reinforced stitching and long carrying handles.',
                'description' => 'Heavyweight 12oz natural cotton canvas tote with reinforced box-stitched handles and expansive gusset. High-durability screen print or embroidered logo for conventions and retail promo.',
            ],
            // 6. Bags & Backpacks - Duffel Bag
            [
                'parent' => 'Bags & Backpacks',
                'sub' => 'Duffel Bags',
                'name' => 'Custom Embroidered Travel & Gym Duffel Bag',
                'sku' => 'QUO-DUFF-006',
                'price' => 32.00,
                'stock' => 120,
                'brand' => 'Mecarvi Gear',
                'weight' => 1.5,
                'dimensions' => json_encode(['length' => 20, 'width' => 10, 'height' => 10]),
                'turnaround' => '4 – 6 business days',
                'images' => [
                    '/assets/images/search/var_duffle_1.png',
                    '/assets/images/search/var_duffle_2.png',
                    '/assets/images/search/var_duffle_3.png',
                    '/assets/images/reported-products/backpack.jpg',
                ],
                'short_description' => 'Water-resistant nylon duffel bag with padded shoulder strap, shoe compartment, and high-density custom embroidery.',
                'description' => 'Rugged 600D water-resistant polyester duffel bag with separate ventilated shoe compartment, padded shoulder strap, and precision embroidered side panels.',
            ],
            // 7. Patches - Leather
            [
                'parent' => 'Patches',
                'sub' => 'Leather Patches',
                'name' => 'Custom Laser-Cut Leather & Embroidered Patches Quote',
                'sku' => 'QUO-PTC-002',
                'price' => 4.50,
                'stock' => 999,
                'brand' => 'Mecarvi Patches',
                'weight' => 0.1,
                'dimensions' => json_encode(['length' => 3, 'width' => 3, 'height' => 0.1]),
                'turnaround' => '2 – 3 business days',
                'images' => [
                    '/images/quotation-products/qp_patch.png',
                    '/images/quotation-products/cat_patches.png',
                ],
                'short_description' => 'High thread-count laser-cut embroidered patch with merrowed border. Choose iron-on, velcro, or sew-on backing.',
                'description' => 'Laser-engraved genuine leather and woven thread crest patches with finished perimeter merrowed edge. Compatible with hats, jackets, backpacks, and tactical gear.',
            ],
            // 8. Patches - Embroidered
            [
                'parent' => 'Patches',
                'sub' => 'Embroidered Patches',
                'name' => 'High-Density Merrowed Edge Iron-On Custom Patches',
                'sku' => 'QUO-IRON-007',
                'price' => 3.75,
                'stock' => 1500,
                'brand' => 'Mecarvi Patches',
                'weight' => 0.1,
                'dimensions' => json_encode(['length' => 3.5, 'width' => 2.5, 'height' => 0.1]),
                'turnaround' => '2 – 4 business days',
                'images' => [
                    '/images/quotation-products/qp_patch.png',
                    '/images/quotation-products/cat_patches.png',
                ],
                'short_description' => 'Commercial-grade heat seal backing with 100% embroidery coverage and vibrant rayon threads.',
                'description' => '100% twill embroidery coverage with high-sheen German polyester threads, heavy-duty iron-on adhesive backing, and custom die-cut borders.',
            ],
            // 9. Accessories - Tumbler
            [
                'parent' => 'Accessories',
                'sub' => 'Tumblers & Drinkware',
                'name' => 'Double-Wall Vacuum Insulated Engraved Tumbler',
                'sku' => 'QUO-TUMB-008',
                'price' => 19.99,
                'stock' => 35,
                'brand' => 'Mecarvi Drinkware',
                'weight' => 0.8,
                'dimensions' => json_encode(['length' => 4, 'width' => 4, 'height' => 8]),
                'turnaround' => '2 – 4 business days',
                'images' => [
                    '/images/quotation-products/qp_tumbler.png',
                    '/images/quotation-products/cat_accessories.png',
                ],
                'short_description' => 'Double-wall vacuum insulated stainless steel tumbler keeps cold beverages chilled for 24 hours.',
                'description' => 'Food-grade 18/8 stainless steel double-wall vacuum insulated 20oz tumbler with clear spill-resistant lid. Custom fiber laser engraving or vibrant screen printed wrap.',
            ],
            // 10. Safety Wear - Vest
            [
                'parent' => 'Safety Wear',
                'sub' => 'Safety Vests',
                'name' => 'ANSI Class 2 High-Visibility Reflective Safety Vest',
                'sku' => 'QUO-VEST-010',
                'price' => 22.00,
                'stock' => 350,
                'brand' => 'Mecarvi Workwear',
                'weight' => 0.6,
                'dimensions' => json_encode(['length' => 10, 'width' => 8, 'height' => 1]),
                'turnaround' => '3 – 5 business days',
                'images' => [
                    '/images/quotation-products/qp_safetyvest.png',
                    '/images/quotation-products/cat_safety.png',
                ],
                'short_description' => 'ANSI/ISEA 107 Class 2 compliant high visibility fluorescent mesh safety vest with 2-inch reflective stripes.',
                'description' => 'ANSI/ISEA 107-2020 Class 2 certified high-visibility mesh safety vest with 2-inch 3M Scotchlite reflective stripes, heavy-duty zipper front, mic tabs, and custom chest and back company logo printing.',
            ],
            // 11. Uniforms - Athletic
            [
                'parent' => 'Uniforms',
                'sub' => 'Athletic Teamwear',
                'name' => 'Sports Team & School Athletic Jersey Customization Quote',
                'sku' => 'QUO-TEA-003',
                'price' => 35.00,
                'stock' => 999,
                'brand' => 'Mecarvi Uniforms',
                'weight' => 0.8,
                'dimensions' => json_encode(['length' => 12, 'width' => 10, 'height' => 2]),
                'turnaround' => '3 – 5 business days',
                'images' => [
                    '/images/quotation-products/cat_uniforms.png',
                    '/images/quotation-products/qp_polo.png',
                ],
                'short_description' => 'Complete athletic department and club team customization quotes including names, player numbers, and varsity crests.',
                'description' => 'Full varsity roster jerseys and warm-ups featuring multi-layer tackle twill, 3D puff embroidery, sublimated gradient side inserts, and heat-pressed player numbers and names.',
            ],
            // 12. Uniforms - Corporate
            [
                'parent' => 'Uniforms',
                'sub' => 'Corporate Uniforms',
                'name' => 'Custom Bulk Corporate Uniform Embroidery Quote',
                'sku' => 'QUO-EMB-001',
                'price' => 28.00,
                'stock' => 999,
                'brand' => 'Mecarvi Uniforms',
                'weight' => 1.0,
                'dimensions' => json_encode(['length' => 14, 'width' => 12, 'height' => 3]),
                'turnaround' => '3 – 5 business days',
                'images' => [
                    '/images/quotation-products/cat_uniforms.png',
                    '/images/quotation-products/qp_polo.png',
                ],
                'short_description' => 'Submit your team roster, logo, and apparel requirements to receive a comprehensive tiered volume wholesale corporate quotation.',
                'description' => 'Comprehensive corporate apparel program packages covering executive button-down oxfords, softshell jackets, hospitality aprons, and technical polo shirts with volume tier pricing.',
            ],
            // 13. Others - Event
            [
                'parent' => 'Others',
                'sub' => 'Event Merchandise',
                'name' => 'Event Merch & Festival Bulk Apparel Production Quote',
                'sku' => 'QUO-EVT-004',
                'price' => 15.00,
                'stock' => 999,
                'brand' => 'Mecarvi Events',
                'weight' => 1.5,
                'dimensions' => json_encode(['length' => 16, 'width' => 12, 'height' => 4]),
                'turnaround' => '3 – 5 business days',
                'images' => [
                    '/images/quotation-products/cat_others.png',
                    '/images/quotation-products/qp_polo.png',
                ],
                'short_description' => 'High-capacity production quoting for concerts, corporate conferences, product launches, and festival merchandise.',
                'description' => 'Large-scale contract merchandise production quote service. Capabilities up to 50,000 units weekly with individual polybagging, barcode retail hangtags, and nationwide split-ship distribution.',
            ],
            // 14. Others - Gift Set
            [
                'parent' => 'Others',
                'sub' => 'Gift Sets',
                'name' => 'Executive VIP Welcome & Corporate Holiday Gift Set',
                'sku' => 'QUO-GIFT-012',
                'price' => 55.00,
                'stock' => 50,
                'brand' => 'Mecarvi Elite',
                'weight' => 3.0,
                'dimensions' => json_encode(['length' => 14, 'width' => 10, 'height' => 5]),
                'turnaround' => '4 – 6 business days',
                'images' => [
                    '/images/quotation-products/cat_others.png',
                    '/images/quotation-products/qp_tumbler.png',
                ],
                'short_description' => 'Curated executive gift box including customized embroidered fleece blanket, tumbler, and leatherette notebook.',
                'description' => 'Luxury corporate presentation box tailored for VIP onboarding, high-value client gifts, and annual holiday appreciation. Features branded keepsake magnetic box and matching custom accessories.',
            ],
        ];

        foreach ($items as $item) {
            $catKey = "{$item['parent']}::{$item['sub']}";
            $category = $resolvedCategories[$catKey] ?? null;

            $attributes = [
                'product_type' => 'quotation',
                'starting_price' => number_format($item['price'], 2, '.', ''),
                'parentCategory' => $item['parent'],
                'subCategory' => $item['sub'],
                'brand' => $item['brand'],
                'brand_name' => $item['brand'],
                'shipping' => true,
                'delivery' => true,
                'storePickup' => true,
                'turnaround' => $item['turnaround'],
                'product_label' => 'Quotation',
                'badge' => 'Custom Quote',
                'promo_badges' => ['Custom Quote', 'Wholesale Pricing', 'Fast Turnaround'],
                'hero_label' => "Quotation Service - {$item['name']}",
                'feature_labels' => [
                    'Tiered Wholesale Pricing',
                    'Free Digital Proofing & Stitch Preview',
                    $item['turnaround'],
                ],
                'highlights' => [
                    ['title' => 'Custom Wholesale Pricing', 'description' => 'Tiered bulk discounts tailored to your exact order size.'],
                    ['title' => 'Free Digital Previews', 'description' => 'Review and approve digital stitch proofs before production.'],
                    ['title' => 'Dedicated Project Manager', 'description' => 'Direct communication with embroidery digitizing experts.'],
                ],
            ];

            Product::updateOrCreate(
                ['sku' => $item['sku']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'short_description' => $item['short_description'],
                    'price' => $item['price'],
                    'sale_price' => 0.00,
                    'cost_price' => null,
                    'category_id' => $category?->id,
                    'stock_quantity' => $item['stock'],
                    'low_stock_threshold' => 10,
                    'weight' => $item['weight'],
                    'dimensions' => $item['dimensions'],
                    'images' => $item['images'],
                    'is_active' => true,
                    'is_featured' => true,
                    'is_digital' => false,
                    'product_type' => 'quotation',
                    'download_url' => null,
                    'seo_title' => $item['name'],
                    'seo_description' => "Request a custom embroidery quotation for {$item['name']}.",
                    'tags' => ['quotation', 'custom-order', 'wholesale', Str::slug($item['parent']), Str::slug($item['sub'])],
                    'attributes' => $attributes,
                    'variants' => [
                        ['name' => 'Custom Order', 'min_quantity' => 1, 'price' => $item['price']],
                    ],
                ]
            );
        }

        $this->command?->info('Seeded ' . count($items) . ' quotation products successfully.');
    }
}
