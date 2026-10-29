<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CharityActivityLog extends Model
{
    use HasFactory;

    protected $table = 'charity_activity_logs';

    protected $fillable = [
        'charity_id',
        'charity_name',
        'action_type',
        'title',
        'description',
        'staff_id',
        'staff_name',
        'staff_role',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function charity()
    {
        return $this->belongsTo(Charity::class, 'charity_id');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
