<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceDisputeQuestion extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_dispute_questions';

    protected $fillable = [
        'dispute_type_id',
        'label',
        'field_key',
        'input_type',
        'placeholder',
        'help_text',
        'options',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function disputeType()
    {
        return $this->belongsTo(EcommerceDisputeType::class, 'dispute_type_id');
    }
}
