<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CharityPayout extends Model
{
    use HasFactory;

    protected $table = 'charity_payouts';

    protected $fillable = [
        'payout_id',
        'charity_id',
        'charity_name',
        'charity_tagline',
        'charity_logo_type',
        'amount',
        'payment_method',
        'reference_or_check',
        'status',
        'scheduled_date',
        'date_paid_or_expected',
        'notes_to_charity',
        'admin_notes',
        'cancellation_reason',
        'cancellation_notes',
        'selected_donation_ids',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'selected_donation_ids' => 'array',
        'completed_at' => 'datetime',
    ];
}
