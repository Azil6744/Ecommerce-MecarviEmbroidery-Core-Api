<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceDisputeType extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_dispute_types';

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function questions()
    {
        return $this->hasMany(EcommerceDisputeQuestion::class, 'dispute_type_id')->orderBy('sort_order');
    }

    public function disputes()
    {
        return $this->hasMany(EcommerceDispute::class, 'dispute_type_id');
    }
}
