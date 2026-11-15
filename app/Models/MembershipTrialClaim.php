<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipTrialClaim extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'matched_rule',
        'matched_claim_id',
        'email_normalized',
        'phone_normalized',
        'card_fingerprint',
        'device_hash',
        'ip_address',
        'ip_flagged',
        'central_membership_id',
    ];

    protected $casts = [
        'ip_flagged' => 'boolean',
    ];
}
