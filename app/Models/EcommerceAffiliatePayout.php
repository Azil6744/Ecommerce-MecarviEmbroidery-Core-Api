<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceAffiliatePayout extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_affiliate_payouts';

    protected $fillable = [
        'payout_number', 'user_id', 'affiliate_id', 'amount', 'method', 'method_detail', 'status',
        'source', 'auto_process', 'commission_ids', 'scheduled_for', 'reference_id', 'notes',
        'reject_reason', 'processed_by', 'approved_at', 'paid_at', 'rejected_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'auto_process' => 'boolean',
        'commission_ids' => 'array',
        'scheduled_for' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function affiliate()
    {
        return $this->belongsTo(EcommerceAffiliate::class, 'affiliate_id');
    }
}
