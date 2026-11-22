<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin moderation actions on product reviews, product Q&A and reported products:
 * what was done, to which record, by which administrator, and when.
 */
class ModerationActivityLog extends Model
{
    public const FEATURE_REVIEW = 'review';
    public const FEATURE_QUESTION = 'question';
    public const FEATURE_PRODUCT_REPORT = 'product_report';

    protected $fillable = [
        'feature',
        'subject_type',
        'subject_id',
        'product_id',
        'action',
        'admin_id',
        'admin_name',
        'admin_email',
        'details',
        'ip_address',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
