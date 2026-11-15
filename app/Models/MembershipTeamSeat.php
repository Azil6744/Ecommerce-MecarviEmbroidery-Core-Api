<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipTeamSeat extends Model
{
    public const LIVE = ['invited', 'active'];

    protected $fillable = [
        'owner_user_id', 'central_membership_id', 'local_membership_id',
        'member_email', 'member_user_id', 'member_name',
        'status', 'invited_at', 'accepted_at', 'removed_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }
}
