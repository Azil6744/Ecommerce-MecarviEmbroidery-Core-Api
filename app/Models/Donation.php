<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Donation extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'txn_id',
        'donor_name',
        'donor_email',
        'charity_name',
        'charity_category',
        'charity_logo_type',
        'amount',
        'payment_method_brand',
        'payment_method_details',
        'payment_method_email',
        'note',
        'status',
    ];
}
