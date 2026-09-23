<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_tax_rates';

    protected $fillable = [
        'state',
        'country',
        'rate',
        'shipping_taxable',
        'label',
        'is_active',
        'effective_date',
    ];

    protected $casts = [
        'rate' => 'decimal:3',
        'shipping_taxable' => 'boolean',
        'is_active' => 'boolean',
    ];
}
