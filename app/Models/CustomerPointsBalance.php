<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerPointsBalance extends Model
{
    use HasFactory;

    protected $table = 'customer_points_balances';

    protected $fillable = [
        'user_id',
        'available_points',
        'pending_points',
        'redeemed_points',
        'expired_points',
        'lifetime_earned',
        'is_locked',
        'locked_reason',
    ];

    protected $casts = [
        'available_points' => 'integer',
        'pending_points' => 'integer',
        'redeemed_points' => 'integer',
        'expired_points' => 'integer',
        'lifetime_earned' => 'integer',
        'is_locked' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
