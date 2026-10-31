<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcommerceOrderTransaction extends Model
{
    public const TYPE_ORDER = 'order';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_PACKAGING = 'packaging';
    public const TYPE_TURNAROUND = 'turnaround';
    public const TYPE_TIP = 'tip';
    public const TYPE_DONATION = 'donation';

    protected $table = 'ecommerce_order_transactions';

    protected $fillable = [
        'order_id', 'order_number', 'type', 'label', 'amount', 'refunded_amount', 'status',
        'payment_method', 'gateway_reference', 'charity_name', 'shipment_group', 'meta',
    ];

    protected $casts = [
        'amount' => 'float',
        'refunded_amount' => 'float',
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_id');
    }
}
