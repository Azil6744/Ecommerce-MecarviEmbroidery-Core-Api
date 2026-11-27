<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserLoginHistory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Persists real login attempts (success / failed) for the admin "Login History" tab.
 */
class LoginHistoryRecorder
{
    public static function record(?User $user, ?string $ip, ?string $userAgent, bool $success, ?string $failureReason = null, ?string $source = 'core'): void
    {
        if (!$user) {
            return;
        }

        try {
            $ua = self::parseUserAgent((string) $userAgent);

            $data = [
                'user_id' => $user->id,
                'ip_address' => $ip,
                'device_type' => $ua['device_type'],
                'device_title' => $ua['browser'],
                'device_details' => trim($ua['browser_version'] . ' • ' . $ua['os']),
                'location' => null,
                'network' => null,
                'status' => $success ? 'Successful' : 'Failed',
            ];

            if (Schema::hasColumn('user_login_histories', 'failure_reason')) {
                $data['failure_reason'] = $success ? null : ($failureReason ?: 'Incorrect password');
                $data['user_agent'] = mb_substr((string) $userAgent, 0, 1000);
                $data['source'] = $source;
            }

            UserLoginHistory::create($data);
        } catch (\Throwable $e) {
            Log::warning('Login history could not be recorded: ' . $e->getMessage());
        }
    }

    public static function parseUserAgent(string $ua): array
    {
        $l = strtolower($ua);

        if (str_contains($l, 'ipad') || str_contains($l, 'tablet')) {
            $deviceType = 'tablet';
        } elseif (str_contains($l, 'iphone') || str_contains($l, 'android') && str_contains($l, 'mobile') || str_contains($l, 'mobile')) {
            $deviceType = 'mobile';
        } else {
            $deviceType = 'desktop';
        }

        $os = 'Unknown OS';
        if (preg_match('/windows nt 10\.0/i', $ua)) { $os = 'Windows 10/11'; }
        elseif (preg_match('/windows/i', $ua)) { $os = 'Windows'; }
        elseif (preg_match('/iphone os ([\d_]+)/i', $ua, $m)) { $os = 'iOS ' . str_replace('_', '.', $m[1]); }
        elseif (preg_match('/ipad.*os ([\d_]+)/i', $ua, $m)) { $os = 'iPadOS ' . str_replace('_', '.', $m[1]); }
        elseif (preg_match('/android ([\d.]+)/i', $ua, $m)) { $os = 'Android ' . $m[1]; }
        elseif (preg_match('/mac os x ([\d_]+)/i', $ua, $m)) { $os = 'macOS ' . str_replace('_', '.', $m[1]); }
        elseif (str_contains($l, 'linux')) { $os = 'Linux'; }

        $browser = 'Unknown Browser';
        $version = '';
        if (preg_match('/edg(?:e|a|ios)?\/([\d.]+)/i', $ua, $m)) { $browser = 'Microsoft Edge'; $version = $m[1]; }
        elseif (preg_match('/(?:opr|opera)\/([\d.]+)/i', $ua, $m)) { $browser = 'Opera'; $version = $m[1]; }
        elseif (preg_match('/(?:chrome|crios)\/([\d.]+)/i', $ua, $m)) { $browser = 'Google Chrome'; $version = $m[1]; }
        elseif (preg_match('/(?:firefox|fxios)\/([\d.]+)/i', $ua, $m)) { $browser = 'Firefox'; $version = $m[1]; }
        elseif (preg_match('/version\/([\d.]+).*safari/i', $ua, $m)) { $browser = 'Safari'; $version = $m[1]; }
        elseif ($ua !== '') { $browser = 'Web Browser'; }

        return [
            'device_type' => $deviceType,
            'os' => $os,
            'browser' => $browser,
            'browser_version' => $version ? 'v' . $version : '',
        ];
    }
}
