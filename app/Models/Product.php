<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'description',
        'short_description',
        'price',
        'loyalty_points_price',
        'sale_price',
        'cost_price',
        'category_id',
        'stock_quantity',
        'low_stock_threshold',
        'weight',
        'dimensions',
        'images',
        'is_active',
        'is_featured',
        'is_digital',
        'product_type',
        'download_url',
        'seo_title',
        'seo_description',
        'tags',
        'attributes',
        'variants',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'loyalty_points_price' => 'integer',
        'sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'weight' => 'decimal:2',
        'images' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'is_digital' => 'boolean',
        'tags' => 'array',
        'attributes' => 'array',
        'variants' => 'array',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        if (is_array($this->images) && ! empty($this->images[0])) {
            $first = $this->images[0];
            if (str_starts_with($first, 'http://') || str_starts_with($first, 'https://') || str_starts_with($first, 'data:')) {
                return $first;
            }
            $clean = preg_replace('#^/?(storage/)+#', '', $first);
            return url('/storage/' . $clean);
        }
        return null;
    }

    // Relationship with category
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Relationship with reviews
    public function reviews()
    {
        return $this->hasMany(EcommerceReview::class);
    }

    // Relationship with product questions
    public function productQuestions()
    {
        return $this->hasMany(EcommerceProductQuestion::class);
    }

    public function approvedReviews()
    {
        return $this->hasMany(EcommerceReview::class)
            ->whereRaw('LOWER(status) = ?', ['approved']);
    }

    // Relationship with cart items
    public function cartItems()
    {
        return $this->hasMany(EcommerceCartItem::class);
    }

    // Relationship with order items
    public function orderItems()
    {
        return $this->hasMany(EcommerceOrderItem::class);
    }

    public function customizationDrafts()
    {
        return $this->hasMany(ProductCustomizationDraft::class);
    }

    public function previewAssets()
    {
        return $this->hasMany(ProductPreviewAsset::class)->orderBy('sort_order');
    }

    public function customizationOptions()
    {
        return $this->hasMany(ProductCustomizationOption::class)->orderBy('sort_order');
    }

    public function pricingRules()
    {
        return $this->hasMany(ProductPricingRule::class)->orderBy('sort_order');
    }

    public function coupons()
    {
        return $this->belongsToMany(EcommerceCoupon::class, 'ecommerce_coupon_product', 'product_id', 'coupon_id');
    }

    /**
     * Applies the offers chosen in the product form. Coupons are linked or unlinked; deals are left
     * alone because a deal's products come from its bundle (managed in the deal editor).
     *
     * @param  array<int, int|string>  $couponIds
     */
    public function syncOfferCoupons(array $couponIds): void
    {
        $dealIds = $this->coupons()->get()->filter(fn (EcommerceCoupon $c) => $c->isDeal())->pluck('id')->all();
        $couponOnly = EcommerceCoupon::query()
            ->whereIn('id', array_map('intval', $couponIds))
            ->get()
            ->reject(fn (EcommerceCoupon $c) => $c->isDeal())
            ->pluck('id')
            ->all();

        $this->coupons()->sync(array_values(array_unique(array_merge($dealIds, $couponOnly))));
    }

    protected static function booted(): void
    {
        // The controller passes the chosen offers for this request through the container (see ProductController)
        static::saved(function (Product $product) {
            if (! app()->bound('product.offer_coupon_ids')) {
                return;
            }
            $ids = app('product.offer_coupon_ids');
            app()->forgetInstance('product.offer_coupon_ids');
            $product->syncOfferCoupons(is_array($ids) ? $ids : []);
        });
    }

    public function relatedProducts()
    {
        return $this->belongsToMany(Product::class, 'product_related_products', 'product_id', 'related_product_id')
            ->withPivot(['relation_type', 'sort_order'])
            ->withTimestamps();
    }
}
