<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FAQItem extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'faq_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'faq_category_id',
        'question',
        'answer',
        'icon',
        'order',
    ];

    /**
     * Get the category that owns this FAQ item.
     */
    public function category()
    {
        return $this->belongsTo(FAQCategory::class, 'faq_category_id');
    }
}

