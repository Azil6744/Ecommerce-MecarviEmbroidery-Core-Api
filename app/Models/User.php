<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'pin',
        'role',
        'avatar',
        'wallet_balance',
        'loyalty_points',
        'banned_at',
        'deactivated_at',
        'last_login_at',
        'notification_preferences',
        'address',
        'dob',
        'gender',
        'membership_status',
        'customer_account_number',
        'business_name',
        'tax_id',
        'business_type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'banned_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'last_login_at' => 'datetime',
        'wallet_balance' => 'decimal:2',
        'loyalty_points' => 'integer',
        'notification_preferences' => 'array',
    ];

    /**
     * Check if user is a super admin or has full admin privileges.
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        if (in_array($this->role, ['super_admin', 'admin', 'editor'])) {
            return true;
        }

        try {
            return $this->hasAnyRole(['super_admin', 'admin', 'editor']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if user is an editor.
     *
     * @return bool
     */
    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    /**
     * Check if user is a viewer.
     *
     * @return bool
     */
    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    /**
     * Check if user has admin access (super_admin, admin, or editor).
     *
     * @return bool
     */
    public function hasAdminAccess(): bool
    {
        return in_array($this->role, ['super_admin', 'admin', 'editor']) || $this->hasAnyRole(['super_admin', 'admin', 'editor']);
    }

    /**
     * Get available roles.
     *
     * @return array
     */
    public static function getAvailableRoles(): array
    {
        return ['super_admin', 'admin', 'editor', 'viewer', 'customer', 'seller', 'staff'];
    }

    // Ecommerce Relationships
    public function affiliate()
    {
        return $this->hasOne(EcommerceAffiliate::class);
    }

    public function referrals()
    {
        return $this->hasMany(EcommerceReferral::class, 'referrer_id');
    }

    public function orders()
    {
        return $this->hasMany(EcommerceOrder::class);
    }

    public function addresses()
    {
        return $this->hasMany(EcommerceAddress::class);
    }

    public function reviews()
    {
        return $this->hasMany(EcommerceReview::class);
    }

    public function cart()
    {
        return $this->hasOne(EcommerceCart::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(EcommerceWalletTransaction::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(EcommercePaymentMethod::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(EcommerceBankAccount::class);
    }

    public function quotations()
    {
        return $this->hasMany(EcommerceQuotation::class);
    }

    public function tickets()
    {
        return $this->hasMany(EcommerceTicket::class);
    }

    public function disputes()
    {
        return $this->hasMany(EcommerceDispute::class);
    }

    public function returns()
    {
        return $this->hasMany(EcommerceReturn::class);
    }

    public function customerVerifications()
    {
        return $this->hasMany(EcommerceCustomerVerification::class);
    }
}
