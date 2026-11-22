<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcommerceWalletAccount extends Model
{
    protected $fillable = [
        'user_id', 'wallet_code', 'wallet_type', 'status',
        'store_credit_balance', 'store_credit_issued_total', 'store_credit_redeemed_total',
        'disable_reason', 'disable_description', 'disable_effective_at', 'disable_funds_action',
        'disabled_at', 'disabled_by', 'last_activity_at',
    ];

    protected $casts = [
        'store_credit_balance' => 'decimal:2',
        'store_credit_issued_total' => 'decimal:2',
        'store_credit_redeemed_total' => 'decimal:2',
        'disabled_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
