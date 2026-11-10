<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceDisputeMessage extends Model
{
    use HasFactory;

    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_ADMIN = 'admin';

    protected $fillable = [
        'ecommerce_dispute_id',
        'user_id',
        'sender_type',
        'sender_name',
        'body',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function dispute()
    {
        return $this->belongsTo(EcommerceDispute::class, 'ecommerce_dispute_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
