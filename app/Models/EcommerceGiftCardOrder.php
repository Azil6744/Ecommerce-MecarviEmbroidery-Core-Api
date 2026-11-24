<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceGiftCardOrder extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_gift_card_orders';

    protected $fillable = [
        'order_number',
        'customer_id',
        'buyer_name',
        'buyer_email',
        'recipient_name',
        'recipient_email',
        'personal_message',
        'giftcard_amount',
        'payment_status',
        'order_status',
        'delivery_date',
        'delivery_method',
        'recipient_phone',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'zip_code',
        'country',
        'card_purpose',
        // Panel fields
        'payment_method',
        'card_brand',
        'card_last4',
        'transaction_id',
        'buyer_phone',
        'buyer_address',
        'buyer_business_name',
        'ip_address',
        'user_agent',
        'flagged_reason',
        'processed_by',
        'processed_by_name',
        'paid_at',
        'approved_at',
        'declined_at',
        'declined_reason',
        'refund_reference',
        'refunded_at',
        'refund_amount',
        'declined_description',
    ];

    protected $casts = [
        'giftcard_amount' => 'decimal:2',
        'delivery_date' => 'datetime',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
        'declined_at' => 'datetime',
        'refunded_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'processed_by' => 'integer',
    ];

    protected static function booted()
    {
        $affiliateHook = function (self $order) {
            if (strtolower((string) $order->payment_status) !== 'paid' || !$order->customer_id) {
                return;
            }
            $buyer = User::find($order->customer_id);
            if ($buyer) {
                \App\Services\AffiliateCommissionService::record(
                    $buyer,
                    \App\Services\AffiliateCommissionService::SOURCE_GIFT_CARD,
                    (string) $order->id,
                    (float) $order->giftcard_amount,
                    null,
                    request()?->ip()
                );
            }
        };

        static::created($affiliateHook);
        static::updated(function (self $order) use ($affiliateHook) {
            if ($order->wasChanged('payment_status')) {
                $affiliateHook($order);
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function notes()
    {
        return $this->hasMany(EcommerceGiftCardNote::class, 'gift_card_order_id');
    }

    public function giftCards()
    {
        // A gift card order might have one or more gift cards generated for it.
        // If we use order_id on gift cards, we can link them.
        return $this->hasMany(EcommerceGiftCard::class, 'order_id');
    }
}
