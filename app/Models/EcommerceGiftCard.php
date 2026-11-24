<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceGiftCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'code',
        'recipient_name',
        'recipient_email',
        'sender_name',
        'initial_balance',
        'current_balance',
        'status',
        'expires_at',
        'delivery_type',
        'message',
        'scheduled_for',
        'purchased_at',
        'redeemed_at',
        'currency',
        
        // Extended fields
        'buyer_user_id',
        'buyer_name',
        'buyer_email',
        'owner_email',
        'issue_type',
        'issued_by_admin_id',
        'disabled_reason',
        'last_used_at',
        
        // Redesign UI fields
        'recipient_phone',
        'design_theme',
        'allow_partial_redemption',
        'restrict_first_redemption',
        'notify_on_redemption',
        'internal_notes',
        'flagged_reason',
        'voided_at',
        'deactivated_at',

        // Admin "Issue Gift Card" popup
        'giftcard_type',
        'issuance_type',
        'expiration_policy',
        'fraud_monitoring',
        'sender_type',
        'sender_user_id',
        'sender_email',
        'sender_phone',
        'sender_business_name',
        'sender_contact_person',
        'shipping_name',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_zip',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'expires_at' => 'date',
        'scheduled_for' => 'datetime',
        'purchased_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'buyer_user_id' => 'integer',
        'issued_by_admin_id' => 'integer',
        'last_used_at' => 'datetime',
        'allow_partial_redemption' => 'boolean',
        'restrict_first_redemption' => 'boolean',
        'notify_on_redemption' => 'boolean',
        'voided_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'fraud_monitoring' => 'boolean',
        'sender_user_id' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        // Enforce 15-digit code generation validation
        static::creating(function ($giftCard) {
            if (empty($giftCard->code)) {
                do {
                    $code = '';
                    for ($i = 0; $i < 15; $i++) {
                        $code .= random_int(0, 9);
                    }
                } while (static::where('code', $code)->exists());
                
                $giftCard->code = $code;
            }
        });

        static::saving(function ($giftCard) {
            if (empty($giftCard->code)) {
                throw new \InvalidArgumentException('Gift card code is required.');
            }
            if (!preg_match('/^\d{15}$/', $giftCard->code) && !preg_match('/^[A-Z0-9-]{4,50}$/i', $giftCard->code)) {
                throw new \InvalidArgumentException('Gift card code must be a 15-digit number or valid alphanumeric string.');
            }
        });

        // Prevent editing code
        static::updating(function ($giftCard) {
            if ($giftCard->isDirty('code')) {
                throw new \InvalidArgumentException('The gift card code cannot be modified.');
            }
        });

        // Prevent deletion
        static::deleting(function ($giftCard) {
            throw new \Exception('Gift cards cannot be deleted.');
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'issued_by_admin_id');
    }

    public function order()
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_id');
    }

    public function giftCardOrder()
    {
        return $this->belongsTo(EcommerceGiftCardOrder::class, 'order_id');
    }

    public function transactions()
    {
        return $this->hasMany(EcommerceGiftCardTransaction::class, 'giftcard_id');
    }

    public function transfers()
    {
        return $this->hasMany(EcommerceGiftCardTransfer::class, 'giftcard_id');
    }

    public function notes()
    {
        return $this->hasMany(EcommerceGiftCardNote::class, 'giftcard_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(EcommerceGiftCardActivityLog::class, 'giftcard_id');
    }
}
