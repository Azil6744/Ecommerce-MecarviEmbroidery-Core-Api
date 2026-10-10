<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceAffiliate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'affiliate_code',
        'total_earnings',
        'total_referrals',
        'total_clicks',
        'status',
        'payout_method',
        'payout_details',
        'tax_info',
        'tax_info_submitted_at',
    ];

    protected $casts = [
        'total_earnings' => 'decimal:2',
        'total_referrals' => 'integer',
        'total_clicks' => 'integer',
        'tax_info_submitted_at' => 'datetime',
    ];

    protected $appends = ['referral_link'];

    public function getReferralLinkAttribute(): string
    {
        $base = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        return $base . '/?ref=' . $this->affiliate_code;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payouts()
    {
        return $this->hasMany(EcommerceAffiliatePayout::class, 'affiliate_id');
    }
}
