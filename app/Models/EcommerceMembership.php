<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceMembership extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_name',
        'status',
        'next_billing_date',
        'price',
        'billing_cycle',
        'pause_starts_at',
        'pause_resumes_at',
        'pause_reason',
        'pause_credit_amount',
        'pause_unused_days',
        'pause_cycle_days',
        'cancel_at_period_end',
        'cancels_at',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_details',
        'cancellation_refund_amount',
        'cancelled_by',
        'deactivation_scheduled_at',
        'deactivated_at',
        'deactivation_reason',
        'deactivation_details',
        'deactivation_refund_amount',
        'plan_id',
        'auto_renew',
        'scheduled_plan_id',
        'scheduled_change_at',
    ];

    protected $casts = [
        'next_billing_date' => 'date',
        'price' => 'decimal:2',
        'pause_starts_at' => 'date',
        'pause_resumes_at' => 'date',
        'pause_credit_amount' => 'decimal:2',
        'pause_unused_days' => 'integer',
        'pause_cycle_days' => 'integer',
        'cancel_at_period_end' => 'boolean',
        'cancels_at' => 'date',
        'cancelled_at' => 'datetime',
        'cancellation_refund_amount' => 'decimal:2',
        'deactivation_scheduled_at' => 'date',
        'deactivated_at' => 'datetime',
        'deactivation_refund_amount' => 'decimal:2',
        'auto_renew' => 'boolean',
        'scheduled_change_at' => 'date',
    ];

    public function activities()
    {
        return $this->hasMany(EcommerceMembershipActivity::class, 'membership_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
