<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
    use HasFactory;

    protected $table = 'admin_todos';

    protected $fillable = [
        'task_id',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'reminder_date',
        'reminder_time',
        'assigned_to_name',
        'assigned_to_email',
        'assigned_to_avatar',
        'is_today_reminder',
        'reminder_title',
        'checklist_items',
        'attachments',
        'order_num',
    ];

    protected $casts = [
        'checklist_items' => 'array',
        'attachments' => 'array',
        'is_today_reminder' => 'boolean',
    ];
}
