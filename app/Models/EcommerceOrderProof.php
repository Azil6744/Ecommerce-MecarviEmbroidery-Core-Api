<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceOrderProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'proof_type',
        'title',
        'file_path',
        'preview_file_path',
        'status',
        'expires_at',
        'approved_at',
        'rejected_at',
        'reviewed_at',
        'rejection_reason',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(EcommerceOrder::class, 'order_id');
    }

    public function comments()
    {
        return $this->hasMany(EcommerceOrderProofComment::class, 'proof_id')->latest();
    }

    public function getFileUrlAttribute(): ?string
    {
        return self::normalizeStorageUrl($this->file_path);
    }

    public function getPreviewUrlAttribute(): ?string
    {
        return self::normalizeStorageUrl($this->preview_file_path ?: $this->file_path);
    }

    public static function normalizeStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $trimmed = trim($path);
        if ($trimmed === '' || str_starts_with($trimmed, 'blob:')) {
            return null;
        }

        if (str_starts_with($trimmed, 'data:')) {
            return $trimmed;
        }

        // Collapse accidental /storage/storage/ prefixes
        $collapsed = preg_replace('#/storage(/storage)+/#', '/storage/', $trimmed) ?? $trimmed;

        // Re-resolve any /storage/... URL (including stale http:// or localhost URLs) against the current host/scheme
        $storagePos = strpos($collapsed, '/storage/');
        if ($storagePos !== false) {
            $relative = ltrim(substr($collapsed, $storagePos + strlen('/storage/')), '/');
            return self::buildPublicStorageUrl($relative);
        }

        if (str_starts_with($collapsed, 'http://') || str_starts_with($collapsed, 'https://')) {
            return $collapsed;
        }

        $clean = ltrim($collapsed, '/');
        if (str_starts_with($clean, 'storage/')) {
            $clean = ltrim(substr($clean, strlen('storage/')), '/');
        }

        return self::buildPublicStorageUrl($clean);
    }

    private static function buildPublicStorageUrl(string $relativePath): string
    {
        $clean = ltrim($relativePath, '/');
        if (app()->runningInConsole() === false && request()) {
            return rtrim(request()->getSchemeAndHttpHost(), '/') . '/storage/' . $clean;
        }

        return asset('storage/' . $clean);
    }
}
