<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceProductQuestionReply extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ecommerce_product_question_replies';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_question_id',
        'user_id',
        'name',
        'role',
        'content',
        'helpful_count',
        'moderation_status',
        'edited_by',
        'edited_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    /**
     * Get the question that the reply belongs to.
     */
    public function question()
    {
        return $this->belongsTo(EcommerceProductQuestion::class, 'product_question_id');
    }

    /**
     * Get the user who posted the reply.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
