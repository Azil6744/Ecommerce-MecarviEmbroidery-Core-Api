<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductReport extends Model
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_UNDER_REVIEW = 'Under Review';
    public const STATUS_RESOLVED = 'Resolved';
    public const STATUS_DISMISSED = 'Dismissed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_UNDER_REVIEW, self::STATUS_RESOLVED, self::STATUS_DISMISSED];

    /** Reports in these states still block a new report from the same customer for the product. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_UNDER_REVIEW];

    /** Final states (plus legacy names) can no longer change. */
    public const FINAL_STATUSES = ['resolved', 'dismissed', 'closed', 'declined', 'approved'];

    public const REASONS = [
        'misleading_description' => 'Misleading description',
        'incorrect_images' => 'Incorrect images',
        'inappropriate_content' => 'Inappropriate content',
        'counterfeit' => 'Counterfeit concerns',
        'other' => 'Other',
    ];

    protected $fillable = [
        'product_id',
        'user_id',
        'issue',
        'description',
        'status',
        'report_code',
        'order_number',
        'quantity',
        'purchase_date',
        'issue_type',
        'reason',
        'category',
        'product_name',
        'product_image',
        'customer_name',
        'customer_email',
        'customer_phone',
        'purchase_location',
        'attachments_count',
        'product_images',
        'admin_feedback',
        'customer_replies',
        'status_history',
    ];

    protected $casts = [
        'product_images' => 'array',
        'admin_feedback' => 'array',
        'customer_replies' => 'array',
        'status_history' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
