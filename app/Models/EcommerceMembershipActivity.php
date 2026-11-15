<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcommerceMembershipActivity extends Model
{
    protected $table = 'ecommerce_membership_activities';

    protected $fillable = [
        'membership_id',
        'user_id',
        'source',
        'action',
        'from_plan',
        'to_plan',
        'amount',
        'payment_method',
        'reference',
        'details',
        'performed_by',
        'actor_name',
        'ip_address',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'details' => 'array',
    ];

    public function membership()
    {
        return $this->belongsTo(EcommerceMembership::class, 'membership_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
