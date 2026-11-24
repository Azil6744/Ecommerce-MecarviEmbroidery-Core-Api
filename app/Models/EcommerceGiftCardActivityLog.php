<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceGiftCardActivityLog extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_gift_card_activity_logs';

    protected $fillable = [
        'giftcard_id',
        'action',
        'admin_id',
        'user_id',
        'old_value',
        'new_value',
        'ip_address',
        'meta',
    ];

    protected $casts = [
        'giftcard_id' => 'integer',
        'admin_id' => 'integer',
        'user_id' => 'integer',
        'meta' => 'array',
    ];

    public function giftCard()
    {
        return $this->belongsTo(EcommerceGiftCard::class, 'giftcard_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
