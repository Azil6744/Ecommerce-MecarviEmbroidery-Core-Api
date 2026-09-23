<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use HasFactory;

    protected $table = 'site_settings';

    protected $fillable = [
        'site_name',
        'seo_site_title',
        'seo_description',
        'seo_keywords',
        'logo',
        'login_logo',
        'favicon',
        'business_panel_logo',
        'user_panel_logo',
        'email_template_logo',
        'button_name',
        'button_url',
        'theme_primary_color',
        'theme_secondary_color',
        'tax_rate',
        'tax_enabled',
        'tax_settings',
        'loyalty_points_earned_per_unit_price',
        'loyalty_points_earned_points',
        'charity_name',
        'charity_donation_enabled',
        'charity_default_amount',
        'packaging_settings',
        'loyalty_settings',
        'charity_settings',
        'tips_settings',
        'downloads_settings',
        'referral_commission_percentage',
        'referral_reward_referrer',
        'referral_reward_referee',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'tax_enabled' => 'boolean',
        'loyalty_points_earned_per_unit_price' => 'decimal:2',
        'loyalty_points_earned_points' => 'integer',
        'charity_donation_enabled' => 'boolean',
        'charity_default_amount' => 'decimal:2',
        'referral_reward_referrer' => 'decimal:2',
        'referral_reward_referee' => 'decimal:2',
        'referral_commission_percentage' => 'decimal:2',
    ];

    /**
     * Logo URL: if stored path (no leading / or http), return asset('storage/...'); otherwise return as-is.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo) {
            return null;
        }
        $logo = $this->logo;
        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://') || str_starts_with($logo, '/')) {
            return $logo;
        }

        return asset('storage/' . $logo);
    }

    /**
     * Favicon URL: if stored path (no leading / or http), return asset('storage/...'); otherwise return as-is.
     */
    public function getFaviconUrlAttribute(): ?string
    {
        if (! $this->favicon) {
            return null;
        }
        $favicon = $this->favicon;
        if (str_starts_with($favicon, 'http://') || str_starts_with($favicon, 'https://') || str_starts_with($favicon, '/')) {
            return $favicon;
        }

        return asset('storage/' . $favicon);
    }

    /**
     * Login logo URL: if stored path (no leading / or http), return asset('storage/...'); otherwise return as-is.
     */
    public function getLoginLogoUrlAttribute(): ?string
    {
        if (! $this->login_logo) {
            return null;
        }
        $loginLogo = $this->login_logo;
        if (str_starts_with($loginLogo, 'http://') || str_starts_with($loginLogo, 'https://') || str_starts_with($loginLogo, '/')) {
            return $loginLogo;
        }

        return asset('storage/' . $loginLogo);
    }

    /**
     * Business panel logo URL: if stored path, return asset('storage/...'); otherwise return as-is.
     */
    public function getBusinessPanelLogoUrlAttribute(): ?string
    {
        if (! $this->business_panel_logo) {
            return null;
        }
        $val = $this->business_panel_logo;
        if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://') || str_starts_with($val, '/')) {
            return $val;
        }
        return asset('storage/' . $val);
    }

    /**
     * User panel logo URL: if stored path, return asset('storage/...'); otherwise return as-is.
     */
    public function getUserPanelLogoUrlAttribute(): ?string
    {
        if (! $this->user_panel_logo) {
            return null;
        }
        $val = $this->user_panel_logo;
        if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://') || str_starts_with($val, '/')) {
            return $val;
        }
        return asset('storage/' . $val);
    }

    /**
     * Email template logo URL: if stored path, return asset('storage/...'); otherwise return as-is.
     */
    public function getEmailTemplateLogoUrlAttribute(): ?string
    {
        if (! $this->email_template_logo) {
            return null;
        }
        $val = $this->email_template_logo;
        if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://') || str_starts_with($val, '/')) {
            return $val;
        }
        return asset('storage/' . $val);
    }
}
