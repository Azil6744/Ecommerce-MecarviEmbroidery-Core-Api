<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcommerceGiftCardNote extends Model
{
    protected $table = 'ecommerce_gift_card_notes';

    protected $fillable = [
        'gift_card_order_id',
        'giftcard_id',
        'user_id',
        'author_name',
        'note',
    ];

    protected $casts = [
        'gift_card_order_id' => 'integer',
        'giftcard_id' => 'integer',
        'user_id' => 'integer',
    ];
}
