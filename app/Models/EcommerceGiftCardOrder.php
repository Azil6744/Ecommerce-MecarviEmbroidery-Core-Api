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
    ];

    protected $casts = [
        'giftcard_amount' => 'decimal:2',
        'delivery_date' => 'datetime',
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

    public function giftCards()
    {
        // A gift card order might have one or more gift cards generated for it.
        // If we use order_id on gift cards, we can link them.
        return $this->hasMany(EcommerceGiftCard::class, 'order_id');
    }
}
