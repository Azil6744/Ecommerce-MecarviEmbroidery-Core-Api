<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyAuditLog extends Model
{
    protected $table = 'loyalty_audit_logs';

    protected $fillable = ['admin_id', 'user_id', 'action', 'reason', 'before', 'after', 'ip_address'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Loyalty audit logs are permanent.'));
        static::updating(fn () => throw new \LogicException('Loyalty audit logs are immutable.'));
    }

    public static function record(string $action, ?int $adminId = null, ?int $userId = null, ?string $reason = null, $before = null, $after = null): self
    {
        return static::create([
            'admin_id' => $adminId,
            'user_id' => $userId,
            'action' => $action,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'ip_address' => request()?->ip(),
        ]);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
