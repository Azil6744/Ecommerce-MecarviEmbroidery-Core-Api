<?php

namespace Database\Seeders;

use App\Models\EcommerceCoupon;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Sample deals for the Deals Manager (php artisan db:seed --class=DealsSeeder).
 * Banners live in each web app at /images/deal-banners/*.svg.
 */
class DealsSeeder extends Seeder
{
    public function run(): void
    {
        // Only products customers can customize and buy online (active, not quotation, not digital).
        $find = fn (string $like) => Product::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $like . '%'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('is_digital')->orWhere('is_digital', false))
            ->where(fn ($q) => $q->whereNull('product_type')->orWhereNotIn(\DB::raw('LOWER(product_type)'), ['quotation', 'digital']))
            ->orderBy('id')->pluck('id')->all();

        $deals = [
            [
                'code' => '10POLOS200', 'slug' => '10-polos-for-200', 'title' => '10 Polos for $200',
                'desc' => 'Premium polo bundle with custom embroidery. Perfect for teams, events and businesses.',
                'badge' => 'Best Seller', 'price' => 200, 'original' => 350, 'products' => $find('polo'), 'qty' => 10,
                'image' => '/images/deal-banners/polo-bundle.svg', 'customer' => 'both', 'status' => 'active',
            ],
            [
                'code' => '5HATS100', 'slug' => '5-hats-for-100', 'title' => '5 Hats for $100',
                'desc' => 'Embroidered hats deal. Multiple styles and colors available.',
                'badge' => 'Hot Deal', 'price' => 100, 'original' => 175, 'products' => $find('cap'), 'qty' => 5,
                'image' => '/images/deal-banners/hat-bundle.svg', 'customer' => 'both', 'status' => 'active',
            ],
            [
                'code' => '3HOODIES120', 'slug' => '3-hoodies-for-120', 'title' => '3 Hoodies for $120',
                'desc' => 'Custom embroidered hoodies. Perfect for companies, schools and organizations.',
                'badge' => 'Limited Time', 'price' => 120, 'original' => 165, 'products' => $find('hoodie'), 'qty' => 3,
                'image' => '/images/deal-banners/hoodie-bundle.svg', 'customer' => 'business', 'status' => 'paused',
            ],
            [
                'code' => '4TSHIRTS50', 'slug' => '4-t-shirts-for-50', 'title' => '4 T-Shirts for $50',
                'desc' => 'Quality embroidered t-shirts. Great for teams, promotions and events.',
                'badge' => 'Top Value', 'price' => 50, 'original' => 100, 'products' => $find('shirt'), 'qty' => 4,
                'image' => '/images/deal-banners/tshirt-bundle.svg', 'customer' => 'personal', 'status' => 'active',
            ],
        ];

        foreach ($deals as $d) {
            $productIds = array_slice($d['products'], 0, 3);
            if (empty($productIds)) {
                continue;
            }
            $perItem = max(1, intdiv($d['qty'], count($productIds)));
            $items = [];
            foreach ($productIds as $i => $id) {
                $qty = $i === count($productIds) - 1 ? $d['qty'] - $perItem * (count($productIds) - 1) : $perItem;
                $items[] = ['product_id' => $id, 'name' => Product::find($id)?->name, 'options' => 'Any Color / Any Size', 'qty' => max(1, $qty)];
            }
            $save = $d['original'] - $d['price'];

            $deal = EcommerceCoupon::updateOrCreate(['code' => $d['code']], [
                'title' => $d['title'],
                'subtitle' => $d['desc'],
                'discount_type' => 'fixed',
                'discount_value' => $save,
                'min_order_amount' => 0,
                'usage_limit' => 500,
                'starts_at' => now()->startOfDay(),
                'expires_at' => now()->addDays(31)->setTime(23, 59),
                'is_active' => $d['status'] === 'active',
                'metadata' => [
                    'is_deal' => true, 'is_bundle' => true, 'deal_category' => 'bundles', 'apply_method' => 'auto',
                    'deal_type' => 'Bundle Deal', 'deal_badge' => $d['badge'],
                    'badge' => 'Save ' . round($save / $d['original'] * 100) . '%',
                    'short_description' => $d['desc'], 'slug' => $d['slug'], 'time_zone' => 'America/New_York',
                    'bundle_items' => $items,
                    'deal_discount_type' => 'fixed_price', 'bundle_price' => $d['price'], 'original_price' => $d['original'], 'savings_amount' => $save,
                    'free_shipping' => true, 'free_local_delivery' => true,
                    'applies_to' => 'all_products', 'exclude_products' => [], 'customer_type' => $d['customer'], 'membership_eligibility' => 'all_customers',
                    'per_customer_limit' => 1, 'per_order_limit' => 1, 'stop_when_limit_reached' => 'no_limit',
                    'applicable_locations' => 'all_locations', 'shipping_destinations' => 'all_countries', 'fulfillment_options' => ['shipping', 'local_delivery'],
                    'stackable_with_coupons' => false, 'stack_with_memberships' => true, 'earn_loyalty_rewards' => true, 'stackable_with_deals' => false,
                    'image_url' => $d['image'], 'deal_status' => $d['status'], 'auto_expire' => true,
                    'full_description' => "Get {$d['title']}. {$d['desc']} Mix colors and sizes across the bundle. Each item includes one embroidered logo placement.",
                    'terms_conditions' => 'Offer valid while supplies last. Cannot be combined with other deals or coupons. Bundle must be purchased in a single order.',
                    'meta_title' => "{$d['title']} - Mecarvi Embroidery",
                    'meta_description' => $d['desc'],
                ],
            ]);
            $deal->products()->sync($productIds);
        }
    }
}
