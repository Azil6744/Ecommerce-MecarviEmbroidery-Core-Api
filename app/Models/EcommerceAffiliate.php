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
        $frontend = env('FRONTEND_URL');
        if ($frontend && !str_contains($frontend, 'api.')) {
            return rtrim($frontend, '/') . '/ref/' . $this->affiliate_code;
        }

        $userPanel = env('USER_PANEL_URL', env('NEXT_PUBLIC_USER_PANEL_BASE_URL', 'https://user.mecarvi.com'));
        $siteSlug = env('SITE_SLUG', 'embroidery');

        return rtrim($userPanel, '/') . '/' . trim($siteSlug, '/') . '/signup?ref=' . $this->affiliate_code;
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
