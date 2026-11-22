<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceProductQuestion extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    public const MODERATION_PENDING = 'pending';
    public const MODERATION_APPROVED = 'approved';
    public const MODERATION_HIDDEN = 'hidden';
    public const MODERATION_STATUSES = [self::MODERATION_PENDING, self::MODERATION_APPROVED, self::MODERATION_HIDDEN];

    protected $table = 'ecommerce_product_questions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'user_id',
        'customer_name',
        'customer_email',
        'question',
        'normalized_question',
        'status',
        'moderation_status',
    ];

    /**
     * Comparable form of a question for duplicate detection: lower case, punctuation removed,
     * whitespace collapsed ("Is it washable?" == "is it  washable").
     */
    public static function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_substr(trim($text), 0, 500);
    }

    public function reports()
    {
        return $this->hasMany(EcommerceQaReport::class, 'product_question_id');
    }

    /**
     * Get the product that the question belongs to.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who asked the question.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the replies for the question.
     */
    public function replies()
    {
        return $this->hasMany(EcommerceProductQuestionReply::class, 'product_question_id');
    }
}
