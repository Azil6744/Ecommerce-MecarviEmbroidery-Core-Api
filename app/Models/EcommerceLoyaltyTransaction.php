<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceLoyaltyTransaction extends Model
{
    use HasFactory;

    // Status vocabulary
    public const STATUS_PENDING = 'pending';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_MANUAL_ADDED = 'manual_added';
    public const STATUS_MANUAL_REMOVED = 'manual_removed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AVAILABLE,
        self::STATUS_REDEEMED,
        self::STATUS_REVERSED,
        self::STATUS_EXPIRED,
        self::STATUS_MANUAL_ADDED,
        self::STATUS_MANUAL_REMOVED,
    ];

    protected $table = 'ecommerce_loyalty_transactions';

    protected $fillable = [
        'user_id',
        'order_id',
        'transaction_type',
        'points',
        'remaining_points',
        'dollar_value',
        'status',
        'reason',
        'reason_details',
        'notes',
        'reference_type',
        'reference_id',
        'reference_date',
        'supporting_document',
        'admin_id',
        'expiration_date',
        'expiry_reminder_sent_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'remaining_points' => 'integer',
        'dollar_value' => 'decimal:2',
        'expiration_date' => 'datetime',
        'expiry_reminder_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Ledger rows must never be deleted: reverse or adjust instead.
        static::deleting(function () {
            throw new \LogicException('Loyalty transactions are permanent and cannot be deleted.');
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order()
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
