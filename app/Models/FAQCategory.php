<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FAQCategory extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'faq_categories';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category_name',
        'description',
        'icon',
        'image',
        'order',
    ];

    /**
     * Get the FAQ items for this category.
     */
    public function faqItems()
    {
        return $this->hasMany(FAQItem::class, 'faq_category_id');
    }
}

