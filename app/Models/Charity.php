<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Charity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tagline',
        'description',
        'contact_person',
        'position_title',
        'year_established',
        'address',
        'city',
        'state_province',
        'postal_code',
        'country',
        'phone',
        'email',
        'web',
        'fax',
        'category',
        'status',
        'assistance_tags',
        'logo_svg_type',
        'image',
        'banner_image',
        'banner_script',
        'preferred_payout_method',
        'bank_name',
        'account_type',
        'routing_number',
        'account_number',
        'check_payable_to',
        'check_address_same_as_charity',
        'check_address',
        'check_city',
        'check_state_province',
        'check_postal_code',
        'check_country',
        'check_memo_notes',
    ];

    protected $casts = [
        'assistance_tags' => 'array',
        'check_address_same_as_charity' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_svg_type) {
            return null;
        }

        if (str_starts_with($this->logo_svg_type, 'http') || str_starts_with($this->logo_svg_type, 'data:')) {
            return $this->logo_svg_type;
        }

        if (str_contains($this->logo_svg_type, '/') || str_contains($this->logo_svg_type, '.')) {
            return '/storage/' . ltrim($this->logo_svg_type, '/');
        }

        return null;
    }

    public function getImageAttribute($value): ?string
    {
        if (!$value) return null;
        if (str_starts_with($value, 'http') || str_starts_with($value, 'data:') || str_starts_with($value, '/storage/')) {
            return $value;
        }
        if (str_contains($value, '/')) {
            return '/storage/' . ltrim($value, '/');
        }
        return $value;
    }

    public function getBannerImageAttribute($value): ?string
    {
        if (!$value) return null;
        if (str_starts_with($value, 'http') || str_starts_with($value, 'data:') || str_starts_with($value, '/storage/')) {
            return $value;
        }
        if (str_contains($value, '/')) {
            return '/storage/' . ltrim($value, '/');
        }
        return $value;
    }
}
