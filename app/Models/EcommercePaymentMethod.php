<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommercePaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'billing_address_id',
        'provider',
        'provider_customer_id',
        'provider_payment_method_id',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'cardholder_name',
        'is_default',
        'card_fingerprint',
    ];

    protected $casts = [
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function billingAddress()
    {
        return $this->belongsTo(EcommerceAddress::class, 'billing_address_id');
    }
}
