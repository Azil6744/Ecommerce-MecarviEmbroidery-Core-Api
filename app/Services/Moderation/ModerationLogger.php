<?php

namespace App\Services\Moderation;

use App\Models\ModerationActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Records admin moderation actions. Logging never blocks the action itself: a failed write
 * is reported to the application log instead.
 */
class ModerationLogger
{
    public static function record(
        Request $request,
        string $feature,
        string $subjectType,
        int $subjectId,
        string $action,
        array $details = [],
        ?int $productId = null
    ): void {
        try {
            $admin = $request->user();
            ModerationActivityLog::create([
                'feature' => $feature,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'product_id' => $productId,
                'action' => $action,
                'admin_id' => $admin?->id,
                'admin_name' => $admin?->name,
                'admin_email' => $admin?->email,
                'details' => $details ?: null,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Moderation log write failed ({$feature}/{$subjectType}#{$subjectId} {$action}): " . $e->getMessage());
        }
    }

    /** Display name of the acting admin, for status histories. */
    public static function adminName(Request $request, string $fallback = 'Admin'): string
    {
        $user = $request->user();

        return $user && ! empty($user->name) ? $user->name : $fallback;
    }
}
