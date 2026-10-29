<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminNote extends Model
{
    use HasFactory;

    protected $table = 'admin_notes';

    protected $fillable = [
        'note_id',
        'title',
        'category',
        'category_color',
        'category_icon',
        'note_date',
        'is_pinned',
        'content',
        'checklist_items',
        'timeline_items',
        'pricing_items',
        'contact_items',
        'bullet_items',
        'gallery_images',
        'attachments',
        'additional_notes',
        'banner_image',
        'thumbnail_image',
        'created_by',
        'order_num',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'checklist_items' => 'array',
        'timeline_items' => 'array',
        'pricing_items' => 'array',
        'contact_items' => 'array',
        'bullet_items' => 'array',
        'gallery_images' => 'array',
        'attachments' => 'array',
        'additional_notes' => 'array',
    ];
}
