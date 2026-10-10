<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceReferral extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_referrals';

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'referred_email',
        'reward_amount_referrer',
        'reward_amount_referee',
        'first_purchase_at',
        'ip_address',
    ];

    protected $casts = [
        'reward_amount_referrer' => 'decimal:2',
        'reward_amount_referee' => 'decimal:2',
        'first_purchase_at' => 'datetime',
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function referredUser()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }
}
