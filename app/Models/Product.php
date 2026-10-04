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

    public function relatedProducts()
    {
        return $this->belongsToMany(Product::class, 'product_related_products', 'product_id', 'related_product_id')
            ->withPivot(['relation_type', 'sort_order'])
            ->withTimestamps();
    }
}
