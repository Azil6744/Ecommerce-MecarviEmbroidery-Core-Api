<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FooterContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_section_heading',
        'phone',
        'fax',
        'email',
        'corporate_address',
        'company_address',
        'hours_mon_fri',
        'hours_sat',
        'hours_sun_holidays',
        'chat_title',
        'chat_subtitle',
        'company_section_heading',
        'support_section_heading',
        'policy_section_heading',
        'policy_center_section_heading',
        'our_brands_section_heading',
        'social_links_section_heading',
        'facebook_url',
        'twitter_url',
        'instagram_url',
        'linkedin_url',
        'tiktok_url',
        'youtube_url',
        'payment_methods_section_heading',
        'copyright_text',
    ];
}
