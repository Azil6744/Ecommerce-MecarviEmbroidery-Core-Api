<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\EcommerceCoupon;
use App\Models\EcommerceReview;
use App\Models\Product;
use App\Models\ProductCustomizationOption;
use App\Models\ProductPreviewAsset;
use App\Models\ProductPricingRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class DemoProductSeeder extends Seeder
{
    private const IMAGE_WIDTH = 1200;
    private const IMAGE_HEIGHT = 900;

    public function run(): void
    {
        $this->seedProductLabels();
        $categories = $this->seedCategories();

        $products = [
            // ==========================================
            // PHYSICAL PRODUCTS
            // ==========================================
            [
                'category' => 'Polos',
                'name' => 'Classic Cotton Embroidered Polo',
                'sku' => 'DEMO-POLO-001',
                'price' => 34.99,
                'sale_price' => 29.99,
                'colors' => [[12, 49, 86], [255, 15, 109], [245, 248, 252]],
                'tags' => ['polo', 'uniform', 'embroidery', 'business', 'bestseller'],
                'quantity' => 48,
                'is_digital' => false,
                'is_featured' => true,
                'product_type' => 'physical',
                'badge' => 'Best Seller',
                'product_label' => 'Best Seller',
                'promo_badges' => ['Best Seller', '15% Off Sale', 'Gallery Ready'],
                'hero_label' => 'Best Seller - Classic Embroidered Polo',
                'rating' => '4.9',
                'review_count' => '42',
                'feature_labels' => ['100% Pique Cotton', 'Reinforced Collar', 'High Density Stitching'],
                'short_description' => 'Classic embroidered polo crafted with 100% breathable pique cotton and precision logo stitching.',
            ],
            [
                'category' => 'Hoodies',
                'name' => 'Premium Logo Embroidered Hoodie',
                'sku' => 'DEMO-HOODIE-002',
                'price' => 58.00,
                'sale_price' => 49.50,
                'colors' => [[17, 24, 39], [217, 4, 41], [229, 231, 235]],
                'tags' => ['hoodie', 'team apparel', 'custom logo', 'fleece', 'featured'],
                'quantity' => 36,
                'is_digital' => false,
                'is_featured' => true,
                'product_type' => 'physical',
                'badge' => 'Featured',
                'product_label' => 'Featured',
                'promo_badges' => ['Featured', 'Heavyweight Fleece', 'Custom Logo Stitch'],
                'hero_label' => 'Staff Pick - Premium Heavyweight Hoodie',
                'rating' => '4.8',
                'review_count' => '38',
                'feature_labels' => ['Heavyweight 380 GSM Fleece', '3D Puff Logo Option', 'Double-Lined Hood'],
                'short_description' => 'Heavyweight fleece hoodie designed for ultra-crisp custom logo embroidery and superior warmth.',
            ],
            [
                'category' => 'Headwear',
                'name' => 'Structured 6-Panel Embroidered Cap',
                'sku' => 'DEMO-CAP-003',
                'price' => 24.00,
                'sale_price' => 19.99,
                'colors' => [[6, 95, 70], [250, 204, 21], [236, 253, 245]],
                'tags' => ['cap', 'hat', 'headwear', 'logo', 'trending'],
                'quantity' => 72,
                'is_digital' => false,
                'is_featured' => false,
                'product_type' => 'physical',
                'badge' => 'Trending',
                'product_label' => 'Trending',
                'promo_badges' => ['Trending', 'Structured Crown', 'Snapback Closure'],
                'hero_label' => 'Trending Cap - High Precision Headwear',
                'rating' => '4.9',
                'review_count' => '56',
                'feature_labels' => ['Structured Pro Crown', 'Antique Buckle or Snapback', 'Seamless Stitch Yoke'],
                'short_description' => 'Structured 6-panel embroidered cap tailored for standout front and side logo embroidery.',
            ],
            [
                'category' => 'Jackets',
                'name' => 'Corporate Softshell Embroidered Jacket',
                'sku' => 'DEMO-JACKET-004',
                'price' => 88.00,
                'sale_price' => 76.00,
                'colors' => [[30, 41, 59], [59, 130, 246], [241, 245, 249]],
                'tags' => ['jacket', 'corporate', 'outerwear', 'weatherproof', 'exclusive'],
                'quantity' => 24,
                'is_digital' => false,
                'is_featured' => true,
                'product_type' => 'physical',
                'badge' => 'Exclusive',
                'product_label' => 'Exclusive',
                'promo_badges' => ['Exclusive', 'Weather Resistant', 'Fleece Lined'],
                'hero_label' => 'Executive Outerwear - Weatherproof Softshell',
                'rating' => '5.0',
                'review_count' => '19',
                'feature_labels' => ['Water & Wind Resistant', 'Micro-Fleece Interior', 'Zippered Pockets'],
                'short_description' => 'Weatherproof corporate softshell jacket featuring premium embroidery for executive and team outerwear.',
            ],
            [
                'category' => 'Bags',
                'name' => 'Custom Embroidered Canvas Tote',
                'sku' => 'DEMO-TOTE-005',
                'price' => 27.50,
                'sale_price' => 22.00,
                'colors' => [[120, 53, 15], [234, 88, 12], [255, 247, 237]],
                'tags' => ['bag', 'tote', 'promotional', 'canvas', 'eco-friendly'],
                'quantity' => 100,
                'is_digital' => false,
                'is_featured' => false,
                'product_type' => 'physical',
                'badge' => 'Eco Friendly',
                'product_label' => 'Eco Friendly',
                'promo_badges' => ['Eco Friendly', '100% Organic Canvas', 'Reinforced Straps'],
                'hero_label' => 'Sustainable Carry - Embroidered Heavy Tote',
                'rating' => '4.7',
                'review_count' => '31',
                'feature_labels' => ['12oz Organic Cotton', 'Stress-Point Reinforcement', 'Vivid Thread Colors'],
                'short_description' => 'Heavy-duty organic canvas tote with reinforced handles, ideal for promotional giveaways and retail merch.',
            ],
            [
                'category' => 'Workwear',
                'name' => 'Heavy Duty Embroidered Work Shirt',
                'sku' => 'DEMO-WORK-006',
                'price' => 42.00,
                'sale_price' => 37.00,
                'colors' => [[71, 85, 105], [14, 165, 233], [248, 250, 252]],
                'tags' => ['workwear', 'staff shirt', 'durable', 'industrial', 'clearance'],
                'quantity' => 60,
                'is_digital' => false,
                'is_featured' => false,
                'product_type' => 'physical',
                'badge' => 'Clearance',
                'product_label' => 'Clearance',
                'promo_badges' => ['Clearance Deal', 'Stain Resistant', 'Industrial Wash Tested'],
                'hero_label' => 'Industrial Workwear - Rugged Double-Stitch Shirt',
                'rating' => '4.8',
                'review_count' => '27',
                'feature_labels' => ['Ripstop Poly-Cotton Blend', 'Soil & Stain Shield', 'Dual Chest Pockets'],
                'short_description' => 'Tough industrial-grade work shirt built to endure demanding environments and industrial wash cycles.',
            ],

            // ==========================================
            // DIGITAL PRODUCTS
            // ==========================================
            [
                'category' => 'Digital Designs',
                'name' => 'Custom Business Card & Branding Design Pack',
                'sku' => 'DIG-BCD-001',
                'price' => 37.00,
                'sale_price' => 29.00,
                'colors' => [[224, 24, 59], [15, 23, 42], [248, 250, 252]],
                'tags' => ['digital', 'business card', 'graphic design', 'branding', 'instant-download'],
                'quantity' => 999,
                'is_digital' => true,
                'is_featured' => true,
                'product_type' => 'digital',
                'badge' => 'Best Seller',
                'product_label' => 'Best Seller',
                'promo_badges' => ['Digital Download', 'Vector AI/EPS + PDF', 'Instant Delivery'],
                'hero_label' => 'Digital Asset - Business Card & Vector Branding Kit',
                'rating' => '4.9',
                'review_count' => '64',
                'feature_labels' => ['Print-Ready 300 DPI CMYK', 'Layered Vector AI, EPS, PDF', 'Commercial License Included'],
                'short_description' => 'Complete digital branding and business card package tailored to your company identity with immediate file download.',
            ],
            [
                'category' => 'Digital Designs',
                'name' => 'Custom Embroidery Logo Vector & DST Digitizing File',
                'sku' => 'DIG-LOG-002',
                'price' => 25.00,
                'sale_price' => 19.00,
                'colors' => [[37, 99, 235], [224, 24, 59], [255, 255, 255]],
                'tags' => ['digital', 'logo', 'embroidery file', 'vector', 'digitizing', 'dst-file'],
                'quantity' => 999,
                'is_digital' => true,
                'is_featured' => true,
                'product_type' => 'digital',
                'badge' => 'Trending',
                'product_label' => 'Trending',
                'promo_badges' => ['Digital Download', 'DST & PES Formats', 'Precision Stitch Map'],
                'hero_label' => 'Digital Stitch File - Machine-Tested DST & PES',
                'rating' => '5.0',
                'review_count' => '83',
                'feature_labels' => ['Machine Tested on Tajima & Barudan', 'Includes DST, PES, JEF, EXP, EMB', 'Color Run Sheet Included'],
                'short_description' => 'Precision digitized stitch file package tested on commercial embroidery machines for minimal thread breaks and crisp detail.',
            ],
            [
                'category' => 'Digital Designs',
                'name' => '3D Puff Cap Digitizing Master File',
                'sku' => 'DIG-PUF-003',
                'price' => 45.00,
                'sale_price' => 35.00,
                'colors' => [[147, 51, 234], [236, 72, 153], [243, 244, 246]],
                'tags' => ['digital', '3d puff', 'digitizing', 'cap embroidery', 'embroidery file'],
                'quantity' => 999,
                'is_digital' => true,
                'is_featured' => false,
                'product_type' => 'digital',
                'badge' => 'Staff Pick',
                'product_label' => 'Staff Pick',
                'promo_badges' => ['Digital Download', '3D Foam Tested', 'Production Ready'],
                'hero_label' => 'Digital Master File - Raised 3D Puff Digitizing',
                'rating' => '4.9',
                'review_count' => '41',
                'feature_labels' => ['High Density Underlay Caps', '3mm & 4mm Foam Calibration', 'Capping Stitch Cutters'],
                'short_description' => 'Master digitized 3D puff embroidery file calibrated with high-density caps for bold, raised dimensional headwear stitching.',
            ],
            [
                'category' => 'Digital Designs',
                'name' => 'Vector Art Redraw & Color Separation Template',
                'sku' => 'DIG-VEC-004',
                'price' => 29.00,
                'sale_price' => 22.00,
                'colors' => [[13, 148, 136], [249, 115, 22], [255, 255, 255]],
                'tags' => ['digital', 'vector redraw', 'color separation', 'screen print', 'new-arrival'],
                'quantity' => 999,
                'is_digital' => true,
                'is_featured' => false,
                'product_type' => 'digital',
                'badge' => 'New Arrival',
                'product_label' => 'New Arrival',
                'promo_badges' => ['Digital Download', 'Screen Print & DTF Ready', 'High Res CMYK/RGB'],
                'hero_label' => 'Digital Vector - High-Res Color Separations',
                'rating' => '4.8',
                'review_count' => '15',
                'feature_labels' => ['Vector Redraw (AI, SVG, EPS)', 'Spot Color Channel Separations', 'Pantone Solid Coated Mappings'],
                'short_description' => 'Clean vector redraw and spot color separation package ready for high-resolution screen printing, DTF, and vinyl cutting.',
            ],

            // ==========================================
            // QUOTATION PRODUCTS (CUSTOM ORDERS / BULK)
            // ==========================================
            [
                'category' => 'Custom Orders',
                'name' => 'Custom Bulk Corporate Uniform Embroidery Quote',
                'sku' => 'QUO-EMB-001',
                'price' => 0.00,
                'sale_price' => 0.00,
                'colors' => [[17, 24, 39], [220, 38, 38], [255, 255, 255]],
                'tags' => ['quotation', 'custom embroidery', 'bulk', 'quote', 'corporate-uniforms'],
                'quantity' => 999,
                'is_digital' => false,
                'is_featured' => true,
                'product_type' => 'quotation',
                'badge' => 'Custom Quote',
                'product_label' => 'Exclusive',
                'promo_badges' => ['Custom Quote', 'Volume Wholesale Discounts', 'Free Digital Proofs'],
                'hero_label' => 'Quotation Service - Corporate Uniform Volume Quote',
                'rating' => '5.0',
                'review_count' => '73',
                'feature_labels' => ['Volume Tiered Discounts', 'Free Virtual & Stitch Proofs', 'Dedicated Account Executive'],
                'short_description' => 'Submit your team roster, logo, and apparel requirements to receive a comprehensive tiered volume wholesale quotation.',
            ],
            [
                'category' => 'Custom Orders',
                'name' => 'Custom Laser-Cut Leather & Embroidered Patches Quote',
                'sku' => 'QUO-PTC-002',
                'price' => 0.00,
                'sale_price' => 0.00,
                'colors' => [[180, 83, 9], [15, 23, 42], [254, 243, 199]],
                'tags' => ['quotation', 'patches', 'leather patch', 'custom patches', 'embroidery-patch'],
                'quantity' => 999,
                'is_digital' => false,
                'is_featured' => true,
                'product_type' => 'quotation',
                'badge' => 'Volume Discount',
                'product_label' => 'Trending',
                'promo_badges' => ['Quotation', 'Genuine & Faux Leather', 'Iron-on & Velcro Backing'],
                'hero_label' => 'Quotation Service - Custom Patches & Leathercraft',
                'rating' => '4.9',
                'review_count' => '49',
                'feature_labels' => ['Laser Engraved & Embroidered', 'Velcro, Iron-On, or Stitch Backing', 'Merrowed Border Edge Finish'],
                'short_description' => 'Get a personalized quote for bespoke leather, woven, or embroidered patches with flexible attachment backings.',
            ],
            [
                'category' => 'Custom Orders',
                'name' => 'Sports Team & School Athletic Jersey Customization Quote',
                'sku' => 'QUO-TEA-003',
                'price' => 0.00,
                'sale_price' => 0.00,
                'colors' => [[30, 58, 138], [239, 68, 68], [255, 255, 255]],
                'tags' => ['quotation', 'teamwear', 'sports jerseys', 'school uniforms', 'roster-names'],
                'quantity' => 999,
                'is_digital' => false,
                'is_featured' => false,
                'product_type' => 'quotation',
                'badge' => 'Fast Turnaround',
                'product_label' => 'Featured',
                'promo_badges' => ['Quotation', 'Custom Names & Numbers', 'Twill & 3D Puff Stitch'],
                'hero_label' => 'Quotation Service - Athletic Roster & Team Uniforms',
                'rating' => '4.9',
                'review_count' => '34',
                'feature_labels' => ['Individual Names & Numbers', 'Tackle Twill & Raised Embroidery', 'Fast Track Tournament Delivery'],
                'short_description' => 'Complete athletic department and club team customization quotes including names, player numbers, and varsity crests.',
            ],
            [
                'category' => 'Custom Orders',
                'name' => 'Event Merch & Festival Bulk Apparel Production Quote',
                'sku' => 'QUO-EVT-004',
                'price' => 0.00,
                'sale_price' => 0.00,
                'colors' => [[15, 118, 110], [245, 158, 11], [255, 255, 255]],
                'tags' => ['quotation', 'events', 'merchandise', 'bulk-apparel', 'fast-turnaround'],
                'quantity' => 999,
                'is_digital' => false,
                'is_featured' => false,
                'product_type' => 'quotation',
                'badge' => 'No Minimums',
                'product_label' => 'New Arrival',
                'promo_badges' => ['Quotation', 'High Volume Production', 'Rush Delivery Available'],
                'hero_label' => 'Quotation Service - Festival & Event Bulk Production',
                'rating' => '4.8',
                'review_count' => '22',
                'feature_labels' => ['Capacity up to 50,000 Pieces/Week', 'Custom Polybagging & Size Stickers', 'Split-Shipment Logistics'],
                'short_description' => 'High-capacity production quoting for concerts, corporate conferences, product launches, and festival merchandise.',
            ],
        ];

        $seededProducts = collect();

        foreach ($products as $index => $demo) {
            $images = $this->generateProductImages($demo['sku'], $demo['colors'], $index);
            $isDigital = !empty($demo['is_digital']);
            $productType = $demo['product_type'] ?? ($isDigital ? 'digital' : 'physical');
            $isQuotation = $productType === 'quotation';

            $product = Product::updateOrCreate(
                ['sku' => $demo['sku']],
                [
                    'name' => $demo['name'],
                    'description' => $this->descriptionFor($demo['name'], $productType),
                    'short_description' => $demo['short_description'] ?? (
                        $isDigital
                            ? 'Instant digital download: includes production-tested digitized stitch files (DST, PES, EMB) and vector artwork with commercial licensing.'
                            : ($isQuotation
                                ? 'Custom high-volume and bespoke embroidery quote request with wholesale volume pricing and rapid response.'
                                : 'Premium custom embroidered apparel with precision high-density stitching and durable fabrics.')
                    ),
                    'price' => $demo['price'],
                    'sale_price' => $demo['sale_price'],
                    'cost_price' => $isQuotation ? null : round($demo['price'] * 0.45, 2),
                    'category_id' => $categories[$demo['category']]->id,
                    'stock_quantity' => $demo['quantity'],
                    'low_stock_threshold' => 10,
                    'weight' => $isDigital || $isQuotation ? 0.0 : 1.25 + ($index * 0.15),
                    'dimensions' => $isDigital ? 'Digital Download' : ($isQuotation ? 'Custom Dimensions' : '12 x 10 x 2 in'),
                    'images' => $images,
                    'is_active' => true,
                    'is_featured' => !empty($demo['is_featured']),
                    'is_digital' => $isDigital,
                    'product_type' => $productType,
                    'download_url' => $isDigital ? 'downloads/demo-asset.zip' : null,
                    'seo_title' => $demo['name'],
                    'seo_description' => "Seeded {$productType} product for Mecarvi Embroidery storefront testing.",
                    'tags' => $demo['tags'],
                    'attributes' => $this->attributesFor($demo, $productType, $isDigital),
                    'variants' => [
                        ['name' => 'Standard', 'min_quantity' => 1, 'price' => $demo['price']],
                    ],
                ]
            );

            $this->seedReviews($product);
            $this->seedPreviewAssets($product, $images);
            $this->seedCustomizationOptions($product);
            $this->seedPricingRules($product);
            $seededProducts->push($product);
        }

        $this->seedProductRelations($seededProducts);
        $this->seedCoupons($seededProducts);
    }

    private function seedProductLabels(): void
    {
        if (! Schema::hasTable('product_labels')) {
            return;
        }

        $labels = [
            ['name' => 'New Arrival', 'slug' => 'new-arrival', 'color_code' => '#22C55E', 'display_order' => 1, 'description' => 'Newly added products to our catalog.'],
            ['name' => 'Best Seller', 'slug' => 'best-seller', 'color_code' => '#3B82F6', 'display_order' => 2, 'description' => 'Our most popular and top-selling items.'],
            ['name' => 'On Sale', 'slug' => 'on-sale', 'color_code' => '#EF4444', 'display_order' => 3, 'description' => 'Discounted promotional products on sale.'],
            ['name' => 'Limited Stock', 'slug' => 'limited-stock', 'color_code' => '#F59E0B', 'display_order' => 4, 'description' => 'Limited stock quantity available.'],
            ['name' => 'Featured', 'slug' => 'featured', 'color_code' => '#7B61FF', 'display_order' => 5, 'description' => 'Featured showcase items across the storefront.'],
            ['name' => 'Exclusive', 'slug' => 'exclusive', 'color_code' => '#EC4899', 'display_order' => 6, 'description' => 'Exclusive catalog items and custom services.'],
            ['name' => 'Trending', 'slug' => 'trending', 'color_code' => '#F97316', 'display_order' => 7, 'description' => 'Currently high-demand and trending items.'],
            ['name' => 'Clearance', 'slug' => 'clearance', 'color_code' => '#DC2626', 'display_order' => 8, 'description' => 'Special clearance priced inventory.'],
            ['name' => 'Staff Pick', 'slug' => 'staff-pick', 'color_code' => '#0EA5E9', 'display_order' => 9, 'description' => 'Recommended directly by our embroidery experts.'],
            ['name' => 'Eco Friendly', 'slug' => 'eco-friendly', 'color_code' => '#16A34A', 'display_order' => 10, 'description' => 'Sustainable and eco-friendly cotton materials.'],
            ['name' => 'Digital Download', 'slug' => 'digital-download', 'color_code' => '#0284C7', 'display_order' => 11, 'description' => 'Instant downloadable digital vector & stitch files.'],
            ['name' => 'Custom Quote', 'slug' => 'custom-quote', 'color_code' => '#8B5CF6', 'display_order' => 12, 'description' => 'Custom quotation for large volume & team orders.'],
            ['name' => 'Volume Discount', 'slug' => 'volume-discount', 'color_code' => '#059669', 'display_order' => 13, 'description' => 'Wholesale bulk volume tier discounts.'],
        ];

        foreach ($labels as $label) {
            DB::table('product_labels')->updateOrInsert(
                ['slug' => $label['slug']],
                array_merge($label, [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    private function seedCategories(): array
    {
        $hasParentId = Schema::hasColumn('categories', 'parent_id');
        $hasSortOrder = Schema::hasColumn('categories', 'sort_order');

        $rootData = [
            'name' => 'Demo Embroidered Products',
            'description' => 'Seeded demo categories for storefront catalog testing.',
            'is_active' => true,
        ];

        if ($hasSortOrder) {
            $rootData['sort_order'] = 1;
        }

        $root = Category::updateOrCreate(
            ['slug' => 'demo-embroidered-products'],
            $rootData
        );

        $names = ['Polos', 'Hoodies', 'Headwear', 'Jackets', 'Bags', 'Workwear', 'Digital Designs', 'Custom Orders', 'Patches'];
        $categories = [];

        foreach ($names as $index => $name) {
            $categoryData = [
                'name' => $name,
                'description' => "Demo {$name} products.",
                'is_active' => true,
            ];

            if ($hasParentId) {
                $categoryData['parent_id'] = $root->id;
            }

            if ($hasSortOrder) {
                $categoryData['sort_order'] = $index + 1;
            }

            $categories[$name] = Category::updateOrCreate(
                ['slug' => 'demo-' . Str::slug($name)],
                $categoryData
            );
        }

        return $categories;
    }

    private function generateProductImages(string $sku, array $colors, int $seed): array
    {
        $paths = [];
        $safeSku = Str::slug(strtolower($sku));

        for ($i = 1; $i <= 4; $i++) {
            $relativePath = "products/demo/{$safeSku}-gallery-{$i}.png";
            $storagePath = storage_path("app/public/{$relativePath}");

            if (! File::exists($storagePath)) {
                File::ensureDirectoryExists(dirname($storagePath));

                $png = $this->makePng($colors, $seed + $i, $i);
                if (File::put($storagePath, $png) === false) {
                    $this->command?->warn("Unable to write demo product image at {$storagePath}. Check storage/app/public permissions.");
                } else {
                    $this->mirrorImageToPublicStorage($relativePath, $png);
                }
            }

            $paths[] = $relativePath;
        }

        return $paths;
    }

    private function mirrorImageToPublicStorage(string $relativePath, string $png): void
    {
        $publicStorageRoot = public_path('storage');

        if (! File::exists($publicStorageRoot) && ! is_link($publicStorageRoot)) {
            $this->command?->warn('Skipped public/storage image mirror because the storage link does not exist. Run: php artisan storage:link');
            return;
        }

        $publicPath = public_path("storage/{$relativePath}");

        try {
            File::ensureDirectoryExists(dirname($publicPath));

            if (File::put($publicPath, $png) === false) {
                $this->command?->warn("Could not mirror demo image to {$publicPath}. Check public/storage permissions.");
            }
        } catch (\Throwable $exception) {
            $this->command?->warn("Could not mirror demo image to public/storage: {$exception->getMessage()}");
        }
    }

    private function makePng(array $colors, int $seed, int $variant): string
    {
        [$primary, $accent, $background] = $colors;
        $width = self::IMAGE_WIDTH;
        $height = self::IMAGE_HEIGHT;
        $raw = '';

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00";
            for ($x = 0; $x < $width; $x++) {
                $t = ($x / max(1, $width - 1) + $y / max(1, $height - 1)) / 2;
                $stripe = ((int) floor(($x + $y + $seed * 37) / (28 + $variant * 4))) % 2 === 0 ? 1 : 0;
                $stitch = (abs(($x % 42) - 21) < 2 || abs(($y % 36) - 18) < 2) ? 1 : 0;
                $cx = $width * (0.42 + $variant * 0.035);
                $cy = $height * 0.48;
                $dx = ($x - $cx) / ($width * 0.29);
                $dy = ($y - $cy) / ($height * 0.34);
                $insidePatch = ($dx * $dx + $dy * $dy) < 1;
                $insideLogo = abs($x - $cx) < $width * 0.12 && abs($y - $cy) < $height * 0.08;

                $rgb = $this->mix($background, $primary, $t * 0.45);
                if ($stripe) {
                    $rgb = $this->mix($rgb, $primary, 0.10);
                }
                if ($insidePatch) {
                    $rgb = $this->mix($primary, $accent, (($x + $seed) % 180) / 520);
                }
                if ($insideLogo) {
                    $rgb = $this->mix($accent, [255, 255, 255], 0.18);
                }
                if ($stitch) {
                    $rgb = $this->mix($rgb, [255, 255, 255], 0.18);
                }

                $raw .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
            }
        }

        return $this->pngFromRawRgb($width, $height, $raw);
    }

    private function pngFromRawRgb(int $width, int $height, string $raw): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return $signature
            . $this->pngChunk('IHDR', $ihdr)
            . $this->pngChunk('IDAT', gzcompress($raw, 6))
            . $this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));
    }

    private function mix(array $a, array $b, float $amount): array
    {
        $amount = max(0, min(1, $amount));

        return [
            (int) round($a[0] + (($b[0] - $a[0]) * $amount)),
            (int) round($a[1] + (($b[1] - $a[1]) * $amount)),
            (int) round($a[2] + (($b[2] - $a[2]) * $amount)),
        ];
    }

    private function descriptionFor(string $name, string $productType = 'physical'): string
    {
        if ($productType === 'digital') {
            return "{$name} is a professional digital design and embroidery digitizing asset package. Download production-ready stitch files and vector graphics with an open commercial license.";
        }
        if ($productType === 'quotation') {
            return "{$name} is a custom production and bulk quotation service. Request custom volume pricing, submit your roster or embroidery artwork, and receive digital stitch proofs within 24 hours.";
        }
        return "{$name} is a premium embroidered apparel item built with durable fabrics, high-density stitching, and customizable thread colorways.";
    }

    private function attributesFor(array $demo, string $productType, bool $isDigital): array
    {
        $badge = $demo['badge'] ?? 'Featured';
        $promoBadges = $demo['promo_badges'] ?? [$badge, 'Gallery ready'];
        $productLabel = $demo['product_label'] ?? $badge;
        $brandName = $demo['brand'] ?? 'Mecarvi Demo';

        $deliveryLabel = $isDigital
            ? 'Instant Digital Download (Immediate Access)'
            : ($productType === 'quotation'
                ? 'Custom Quote Response within 24 Hours'
                : '3-5 business days standard delivery');

        $heroLabel = $demo['hero_label'] ?? (
            $isDigital
                ? 'Digital Design & Digitizing File'
                : ($productType === 'quotation'
                    ? 'Custom Bulk Embroidery & Production Quotation'
                    : 'Premium Custom Embroidered Apparel')
        );

        $featureLabels = $demo['feature_labels'] ?? (
            $isDigital
                ? ['Instant Download', 'DST & PES Formats', 'Commercial License Included']
                : ($productType === 'quotation'
                    ? ['Volume Tier Discounts', 'Free Digital Proofing', 'Dedicated Account Specialist']
                    : ['Premium Quality Embroidery', 'Fast Turnaround', 'No Setup Fees'])
        );

        $highlights = $demo['highlights'] ?? (
            $isDigital
                ? [
                    ['title' => 'Instant Digital Delivery', 'description' => 'Download vector AI/EPS and digitized stitch files immediately.'],
                    ['title' => 'Industry Standard Files', 'description' => 'Tested DST, PES, EMB and vector files ready for production.'],
                    ['title' => 'Commercial License', 'description' => 'Full commercial rights for unlimited physical product use.'],
                ]
                : ($productType === 'quotation'
                    ? [
                        ['title' => 'Custom Wholesale Pricing', 'description' => 'Tiered bulk discounts tailored to your exact order size.'],
                        ['title' => 'Free Digital Previews', 'description' => 'Review and approve digital stitch proofs before production.'],
                        ['title' => 'Dedicated Project Manager', 'description' => 'Direct communication with embroidery digitizing experts.'],
                    ]
                    : [
                        ['title' => 'Expert Embroidery', 'description' => 'Precision high-density stitching for a durable, premium finish.'],
                        ['title' => 'Fast Turnaround', 'description' => '3-5 business days standard production time.'],
                        ['title' => 'No Setup Fees', 'description' => 'Free digitizing setup on all qualifying orders.'],
                    ])
        );

        $primaryCta = $productType === 'quotation'
            ? 'Request Custom Quote'
            : ($isDigital ? 'Download Files' : 'Start Your Order');

        $secondaryCta = $productType === 'quotation'
            ? 'Speak to Specialist'
            : ($isDigital ? 'View Sample Stitches' : 'Request Quote');

        $previewNote = $isDigital
            ? 'Immediate file download after checkout'
            : ($productType === 'quotation'
                ? 'Receive custom pricing and digital mockups within 24 hours'
                : 'Get a digital preview within hours');

        return [
            'badge' => $badge,
            'promo_badges' => $promoBadges,
            'product_label' => $productLabel,
            'product_labels' => $demo['product_labels'] ?? ['T-Shirts', 'Hoodies', 'Polos', 'Hats', 'Jackets', 'Workwear', 'Digital', 'Quotations'],
            'brand_name' => $brandName,
            'product_type' => $productType,
            'is_digital' => $isDigital,
            'color_options' => $isDigital ? ['Digital Vector RGB/CMYK'] : ['Navy', 'Black', 'White', 'Red', 'Royal Blue', 'Forest Green'],
            'print_attributes' => $isDigital ? ['Vector AI', 'DST Stitch File', 'PES File', 'PDF Proof'] : ['Standard Embroidery', '3D Puff', 'Patch Applique'],
            'delivery_time_label' => $deliveryLabel,
            'hero_label' => $heroLabel,
            'rating_value' => $demo['rating'] ?? '4.9',
            'review_count' => $demo['review_count'] ?? '28',
            'feature_labels' => $featureLabels,
            'highlights' => $highlights,
            'promo_contact_text' => 'Contact our team for custom embroidery options and volume orders.',
            'why_order_title' => $productType === 'quotation' ? 'Why Request a Custom Quote with Mecarvi?' : ($isDigital ? 'Why Choose Our Digital Assets?' : 'Why Order This Product?'),
            'help_section_title' => 'Need Help with Your Order?',
            'help_cards' => [
                ['title' => 'Call Us', 'text' => '(877) 853-3484'],
                ['title' => 'Email Us', 'text' => 'contact@mecarviembroidery.com'],
                ['title' => 'Live Chat', 'text' => 'Chat with our team'],
                ['title' => 'Support Ticket', 'text' => 'Submit a ticket'],
            ],
            'customization_title' => 'Customize Your Embroidery - Simple Steps',
            'customization_steps' => ['Choose Product', 'Embroidery Type', 'Placement', 'Size', 'Thread Colors', 'Quantity'],
            'select_product_title' => 'Select Product',
            'live_preview_title' => 'Live Preview',
            'summary_title' => 'Your Customization Summary',
            'delivery_title' => 'Estimated Delivery',
            'delivery_window' => $isDigital ? 'Instant Download' : ($productType === 'quotation' ? 'Quote within 24h' : '3-5 Business Days'),
            'delivery_note' => $isDigital ? 'Instant download link provided via email and dashboard.' : 'Production begins once digital proof is approved.',
            'upload_help_title' => 'Upload Logo / Artwork',
            'upload_help_text' => 'JPG, PNG, PDF, AI, EPS, DST (Max 25MB)',
            'embroidery_type' => 'Standard Embroidery',
            'placement' => 'Left Chest',
            'size_label' => 'Standard (4" Wide)',
            'quantity_label' => 'Pieces',
            'quantity' => (string) ($demo['quantity'] ?? 10),
            'thread_colors' => ['#0c3156', '#ff0f6d', '#ffffff', '#22c55e'],
            'primary_cta_label' => $primaryCta,
            'secondary_cta_label' => $secondaryCta,
            'preview_note' => $previewNote,
            'process_title' => 'Our Production & Delivery Process',
            'process_steps' => [
                ['title' => 'Design & Order Review', 'description' => 'We review your logo and product specifications.'],
                ['title' => 'Precision Digitizing', 'description' => 'Our master digitizers prepare an optimal stitch file.'],
                ['title' => 'Production & Stitching', 'description' => 'High-speed industrial embroidery machines stitch your order.'],
                ['title' => 'Quality Control', 'description' => 'Hand inspection for clean thread trimming and stitch tension.'],
                ['title' => 'Fast Dispatch', 'description' => 'Securely packaged and shipped with full tracking.'],
            ],
            'reviews_title' => 'Verified Customer Reviews',
            'special_offers_title' => 'Special Offers & Promotions',
            'offer_cards' => [
                ['title' => '10% OFF', 'subtitle' => 'ON ALL ORDERS', 'code' => 'MECARVI10', 'note' => 'Valid for all catalog products', 'side' => 'pink'],
                ['title' => 'GBP15 OFF', 'subtitle' => 'ORDERS OVER GBP150', 'code' => 'SAVE15', 'note' => 'Valid for bulk orders', 'side' => 'blue'],
            ],
            'recent_work_title' => 'Our Recent Production Work',
            'recent_work_cta_label' => 'View Full Portfolio ->',
            'featured_products_title' => 'Featured Embroidery Products',
            'final_cta_title' => 'Ready to Start Your Custom Order?',
            'final_cta_text' => 'Upload your logo, choose your options, and get a digital stitch preview within hours.',
            'upload_design_label' => 'Upload Design File',
        ];
    }

    private function seedReviews(Product $product): void
    {
        $reviews = [
            ['customer_name' => 'James D.', 'rating' => 5, 'title' => 'Excellent quality', 'comment' => 'The stitching looks clean and the product gallery works nicely.'],
            ['customer_name' => 'Sarah R.', 'rating' => 5, 'title' => 'Great demo item', 'comment' => 'Perfect sample product for testing the storefront flow.'],
            ['customer_name' => 'Maya K.', 'rating' => 4, 'title' => 'Looks professional', 'comment' => 'The product details and images are easy to review.'],
        ];

        foreach ($reviews as $review) {
            EcommerceReview::updateOrCreate(
                [
                    'product_id' => (string) $product->id,
                    'customer_name' => $review['customer_name'],
                ],
                [
                    'rating' => $review['rating'],
                    'title' => $review['title'],
                    'comment' => $review['comment'],
                    'status' => 'Approved',
                ]
            );
        }
    }

    private function seedPreviewAssets(Product $product, array $images): void
    {
        $sides = ['front', 'back', 'left', 'right'];

        foreach ($images as $index => $image) {
            ProductPreviewAsset::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'side' => $sides[$index] ?? 'front',
                    'sort_order' => $index,
                ],
                [
                    'image_path' => $image,
                    'is_active' => true,
                    'metadata' => ['label' => ucfirst($sides[$index] ?? 'front')],
                ]
            );
        }
    }

    private function seedCustomizationOptions(Product $product): void
    {
        if (! Schema::hasTable('product_customization_options')) {
            return;
        }

        if ($product->product_type === 'digital') {
            $options = [
                ['format', 'format_dst_pes', 'DST & PES Embroidery Files', 0],
                ['format', 'format_all_vector', 'All Formats (DST, PES, EMB, AI, SVG, PDF)', 5],
                ['license', 'commercial_standard', 'Standard Commercial License (Up to 5,000 units)', 0],
                ['license', 'commercial_unlimited', 'Unlimited Extended Commercial License', 15],
                ['turnaround', 'instant_auto', 'Instant Automatic Download', 0],
                ['turnaround', 'priority_support', 'Instant Download + 24h Digitizer Revision Support', 8],
            ];
        } elseif ($product->product_type === 'quotation') {
            $options = [
                ['embroidery_type', 'standard_flat', 'Standard Flat Embroidery', 0],
                ['embroidery_type', '3d_puff_raised', '3D Puff Raised Embroidery', 0],
                ['embroidery_type', 'laser_patch', 'Laser Cut Patch / Applique', 0],
                ['placement', 'left_chest', 'Left Chest (4" max)', 0],
                ['placement', 'full_back', 'Full Back Jacket (10" - 12")', 0],
                ['placement', 'cap_front', 'Cap Center Front', 0],
                ['turnaround', 'standard_5_7', 'Standard Production (5-7 Days)', 0],
                ['turnaround', 'rush_48h', 'Rush Production (48-72 Hours)', 0],
            ];
        } else {
            $options = [
                ['embroidery_type', 'embroidery', 'Embroidery', 0],
                ['embroidery_type', 'premium_embroidery', 'Premium Embroidery', 4],
                ['placement', 'left_chest', 'Left Chest', 0],
                ['placement', 'full_back', 'Full Back', 8],
                ['size', 'standard_4_wide', 'Standard (4" Wide)', 0],
                ['size', 'large_8_wide', 'Large (8" Wide)', 6],
                ['thread_colors', 'three_colors', '3 Colors', 0],
                ['thread_colors', 'six_colors', '6 Colors', 5],
            ];
        }

        foreach ($options as $index => [$type, $key, $label, $modifier]) {
            ProductCustomizationOption::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'option_type' => $type,
                    'option_key' => $key,
                ],
                [
                    'label' => $label,
                    'price_modifier' => $modifier,
                    'sort_order' => $index,
                    'is_active' => true,
                    'metadata' => [],
                ]
            );
        }
    }

    private function seedPricingRules(Product $product): void
    {
        if (! Schema::hasTable('product_pricing_rules')) {
            return;
        }

        $rules = [
            ['min' => 50, 'max' => 99, 'type' => 'percentage', 'value' => -5],
            ['min' => 100, 'max' => null, 'type' => 'percentage', 'value' => -10],
        ];

        foreach ($rules as $index => $rule) {
            ProductPricingRule::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'rule_type' => 'quantity',
                    'min_quantity' => $rule['min'],
                    'max_quantity' => $rule['max'],
                ],
                [
                    'adjustment_type' => $rule['type'],
                    'adjustment_value' => $rule['value'],
                    'sort_order' => $index,
                    'is_active' => true,
                    'metadata' => ['label' => "{$rule['value']}% bulk adjustment"],
                ]
            );
        }
    }

    private function seedProductRelations($products): void
    {
        if (! Schema::hasTable('product_related_products')) {
            return;
        }

        $ids = $products->pluck('id')->values();

        foreach ($products as $product) {
            $relatedIds = $ids->filter(fn ($id) => $id !== $product->id)->values();

            foreach (['related', 'recent_work', 'featured'] as $type) {
                foreach ($relatedIds->take($type === 'featured' ? 8 : 5) as $index => $relatedId) {
                    DB::table('product_related_products')->updateOrInsert(
                        [
                            'product_id' => $product->id,
                            'related_product_id' => $relatedId,
                            'relation_type' => $type,
                        ],
                        [
                            'relation_type' => $type,
                            'sort_order' => $index,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        }
    }

    private function seedCoupons($products): void
    {
        if (! Schema::hasTable('ecommerce_coupons') || ! Schema::hasTable('ecommerce_coupon_product')) {
            return;
        }

        $mecarvi10 = EcommerceCoupon::updateOrCreate(
            ['code' => 'MECARVI10'],
            [
                'title' => '10% OFF',
                'subtitle' => 'ON ALL ORDERS',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'min_order_amount' => 0,
                'is_active' => true,
                'metadata' => ['side' => 'pink', 'note' => 'Valid demo coupon'],
            ]
        );

        $save15 = EcommerceCoupon::updateOrCreate(
            ['code' => 'SAVE15'],
            [
                'title' => 'GBP15 OFF',
                'subtitle' => 'ORDERS OVER GBP150',
                'discount_type' => 'fixed',
                'discount_value' => 15,
                'min_order_amount' => 150,
                'is_active' => true,
                'metadata' => ['side' => 'blue', 'note' => 'Valid demo coupon'],
            ]
        );

        foreach ($products as $product) {
            $product->coupons()->syncWithoutDetaching([$mecarvi10->id, $save15->id]);
        }
    }
}
