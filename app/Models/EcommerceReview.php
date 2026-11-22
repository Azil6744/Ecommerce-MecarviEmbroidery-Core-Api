<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceReview extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'product_id',
        'user_id',
        'customer_name',
        'customer_email',
        'order_number',
        'verified_buyer',
        'rating',
        'title',
        'comment',
        'images',
        'status',
        'admin_reply',
        'admin_reply_at',
    ];

    /**
     * Never part of public output (product pages, product lists). Admin screens and the
     * customer's own reviews opt back in with makeVisible(self::PRIVATE_FIELDS).
     */
    public const PRIVATE_FIELDS = ['customer_email', 'order_number'];

    protected $hidden = ['customer_email', 'order_number'];

    protected $casts = [
        'rating' => 'integer',
        'verified_buyer' => 'boolean',
        'images' => 'array',
        'admin_reply_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (EcommerceReview $review) {
            if (blank($review->status)) {
                $review->status = self::STATUS_PENDING;
            }
        });

        static::saved(function (EcommerceReview $review) {
            if ($review->status === self::STATUS_APPROVED && !empty($review->user_id)) {
                try {
                    \App\Services\LoyaltyService::awardActivity(
                        (int) $review->user_id,
                        'review',
                        'review_reward',
                        'Loyalty points for approved product review.',
                        'review',
                        (string) $review->id
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Review model loyalty award failed: ' . $e->getMessage());
                }
            }
        });
    }

    public function getCustomerNameAttribute($value)
    {
        if (!empty($value) && $value !== 'Verified Customer') {
            return $value;
        }
        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->name ?: ($this->user->email ?: $value);
        }
        return $value ?: 'Customer';
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_number', 'order_number');
    }
}
