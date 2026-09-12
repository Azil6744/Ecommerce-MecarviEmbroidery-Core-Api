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

    protected $fillable = [
        'product_id',
        'user_id',
        'customer_name',
        'rating',
        'title',
        'comment',
        'status',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (EcommerceReview $review) {
            if (blank($review->status)) {
                $review->status = self::STATUS_PENDING;
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
}
