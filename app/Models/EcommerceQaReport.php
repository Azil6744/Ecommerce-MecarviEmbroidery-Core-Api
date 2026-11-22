<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer's report of an inappropriate product question or answer.
 */
class EcommerceQaReport extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    public const REASONS = [
        'spam' => 'Spam or advertising',
        'offensive' => 'Offensive or abusive',
        'personal_info' => 'Contains personal information',
        'off_topic' => 'Not about this product',
        'incorrect' => 'Incorrect or misleading answer',
        'other' => 'Other',
    ];

    protected $table = 'ecommerce_qa_reports';

    protected $fillable = [
        'product_question_id',
        'reply_id',
        'user_id',
        'reason',
        'comment',
        'status',
        'handled_by',
        'handled_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    protected $appends = ['reason_label'];

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst(str_replace('_', ' ', (string) $this->reason));
    }

    public function question()
    {
        return $this->belongsTo(EcommerceProductQuestion::class, 'product_question_id');
    }

    public function reply()
    {
        return $this->belongsTo(EcommerceProductQuestionReply::class, 'reply_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
