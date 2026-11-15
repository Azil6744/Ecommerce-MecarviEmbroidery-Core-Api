<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcommerceConversation;
use App\Models\EcommerceMembership;
use App\Models\EmailNotificationLog;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin → member messaging for the "Send Message" popup on Manage Membership.
 *
 * GET  /admin/memberships/{id}/contact  Live contact card (name, email, phone, address, account no., plan, status).
 * POST /admin/memberships/{id}/message  Sends the message by email and/or as an in-app message (customer Messages inbox).
 */
class AdminMembershipMessageController extends Controller
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public function contact(Request $request, $id)
    {
        $email = $this->resolveEmail($id, $request->query('email'));
        if (! $email) {
            return response()->json(['success' => false, 'message' => 'Customer for this membership could not be found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->buildContact($id, $email)]);
    }

    public function send(Request $request, $id)
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:' . self::MAX_MESSAGE_LENGTH],
            'send_email' => ['nullable', 'boolean'],
            'send_in_app' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ], [
            'attachment.mimes' => 'Attachment must be a JPG, PNG or PDF file.',
            'attachment.max' => 'Attachment must be 10MB or smaller.',
        ]);

        $sendEmail = $request->boolean('send_email');
        $sendInApp = $request->boolean('send_in_app');
        if (! $sendEmail && ! $sendInApp) {
            return response()->json(['success' => false, 'message' => 'Select at least one delivery option (Email or In-App Notification).'], 422);
        }

        $email = $this->resolveEmail($id, $validated['email'] ?? null);
        if (! $email) {
            return response()->json(['success' => false, 'message' => 'Customer for this membership could not be found.'], 404);
        }

        $contact = $this->buildContact($id, $email);
        $subject = trim($validated['subject']);
        $body = trim($validated['message']);
        $admin = $request->user();

        /** @var UploadedFile|null $file */
        $file = $request->file('attachment');
        $attachment = null;
        if ($file) {
            $path = $file->store('membership-messages/' . now()->format('Y/m'), 'public');
            $sizeBytes = $file->getSize() ?: 0;
            $attachment = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'absolute_path' => storage_path('app/public/' . $path),
                'url' => asset('storage/' . $path),
                'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size' => $sizeBytes > 1048576 ? number_format($sizeBytes / 1048576, 1) . ' MB' : max(1, round($sizeBytes / 1024)) . ' KB',
            ];
        }

        $result = ['email' => null, 'in_app' => null];
        $errors = [];

        if ($sendInApp) {
            try {
                $result['in_app'] = $this->deliverInApp($contact, $subject, $body, $attachment, $admin, $id);
            } catch (\Throwable $e) {
                Log::error('Membership in-app message failed: ' . $e->getMessage(), ['membership' => $id]);
                $errors[] = 'In-app notification failed: ' . $e->getMessage();
            }
        }

        if ($sendEmail) {
            $log = $this->deliverEmail($contact, $subject, $body, $attachment, $admin);
            $result['email'] = [
                'status' => $log->status,
                'recipient' => $log->recipient_email,
                'log_id' => $log->id,
                'error' => $log->error_message,
            ];
            if ($log->status !== 'sent') {
                $errors[] = 'Email could not be sent: ' . ($log->error_message ?: 'unknown mail error');
            }
        }

        $delivered = ($result['in_app'] !== null) || (($result['email']['status'] ?? null) === 'sent');

        if ($delivered) {
            // Central does not see messages, so they are logged here for both stores.
            \App\Services\MembershipActivityLog::admin(
                $request,
                $request->input('source') === 'local' ? 'local' : 'central',
                is_numeric($id) ? (int) $id : null,
                \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->value('id'),
                'message_sent',
                ['details' => ['subject' => $subject, 'channels' => array_values(array_filter([$sendEmail ? 'email' : null, $sendInApp ? 'in_app' : null]))]]
            );
        }

        return response()->json([
            'success' => $delivered,
            'message' => $delivered
                ? ($errors ? 'Message partially delivered. ' . implode(' ', $errors) : 'Message sent to ' . $contact['name'] . '.')
                : implode(' ', $errors),
            'data' => array_merge($result, ['sent_at' => now()->toIso8601String()]),
        ], $delivered ? 200 : 502);
    }

    // ---------------------------------------------------------------------
    // Contact resolution
    // ---------------------------------------------------------------------

    private function centralDb()
    {
        try {
            $connection = DB::connection('central_auth');
            $connection->getPdo();
            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }

    private function numericId($id): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $id);
        return $digits === '' ? null : (int) $digits;
    }

    private function resolveEmail($id, ?string $fallbackEmail): ?string
    {
        $membershipId = $this->numericId($id);
        $central = $this->centralDb();

        if ($central && $membershipId) {
            $email = $central->table('central_memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.id', $membershipId)
                ->value('u.email');
            // When the caller also passes an email it must match the membership owner (central ids can collide with local ids).
            if ($email && (! $fallbackEmail || strcasecmp($email, $fallbackEmail) === 0)) {
                return $email;
            }
        }

        if ($membershipId) {
            $local = EcommerceMembership::with('user')->find($membershipId);
            $email = $local?->user?->email;
            if ($email && (! $fallbackEmail || strcasecmp($email, $fallbackEmail) === 0)) {
                return $email;
            }
        }

        return $fallbackEmail ?: null;
    }

    private function buildContact($id, string $email): array
    {
        $central = $this->centralDb();
        $centralUser = $central ? $central->table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])->first() : null;
        $localUser = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        $membership = null;
        $membershipId = $this->numericId($id);
        if ($central && $centralUser && $membershipId) {
            $membership = $central->table('central_memberships')->where('id', $membershipId)->where('user_id', $centralUser->id)->first();
        }
        if (! $membership && $localUser) {
            $membership = EcommerceMembership::where('user_id', $localUser->id)
                ->when($membershipId, fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$membershipId]))
                ->orderByDesc('updated_at')
                ->first();
        }

        $accountType = strtolower((string) ($centralUser->account_type ?? ''));
        if ($accountType === '') {
            $accountType = ($localUser && ($localUser->role === 'business' || ! empty($localUser->business_name))) ? 'business' : 'personal';
        }

        $name = $centralUser->name ?? $localUser?->name
            ?? trim(($centralUser->first_name ?? '') . ' ' . ($centralUser->last_name ?? ''));

        $avatar = $centralUser->avatar_url ?? null;
        if (! $avatar && $localUser?->avatar) {
            $avatar = str_starts_with($localUser->avatar, 'http') ? $localUser->avatar : asset('storage/' . ltrim($localUser->avatar, '/'));
        }

        return [
            'name' => $name ?: $email,
            'email' => $centralUser->email ?? $localUser?->email ?? $email,
            'phone' => $centralUser->phone ?? $localUser?->phone ?? null,
            'address' => $this->formatAddress($localUser, $centralUser),
            'avatar' => $avatar,
            'account_type' => $accountType,
            'account_number' => $centralUser->account_number ?? $localUser?->customer_account_number ?? null,
            'account_status' => $centralUser->account_status ?? $localUser?->status ?? null,
            'business_name' => $localUser?->business_name,
            'business_type' => $localUser?->business_type,
            'member_since' => $centralUser->created_at ?? $localUser?->created_at?->toIso8601String(),
            'local_user_id' => $localUser?->id,
            'central_user_id' => $centralUser->id ?? null,
            'membership' => $membership ? [
                'id' => $membership->id,
                'plan_name' => $membership->plan_name,
                'status' => strtolower((string) $membership->status),
                'next_billing_date' => $membership->next_billing_date ?? null,
            ] : null,
        ];
    }

    private function formatAddress(?User $localUser, ?object $centralUser): ?string
    {
        if ($localUser && Schema::hasTable('ecommerce_addresses')) {
            $row = DB::table('ecommerce_addresses')
                ->where('user_id', $localUser->id)
                ->orderByDesc('is_default')
                ->orderByDesc('updated_at')
                ->first();
            if ($row) {
                $street = trim(implode(', ', array_filter([$row->address_line_1 ?? null, $row->address_line_2 ?? null]))) ?: ($row->address ?? '');
                $stateZip = trim(($row->state ?? '') . ' ' . ($row->postal_code ?? $row->zip_code ?? ''));
                $formatted = implode(', ', array_filter([$street, $row->city ?? null, $stateZip, $row->country ?? null], fn ($p) => trim((string) $p) !== ''));
                if ($formatted !== '') {
                    return $formatted;
                }
            }
        }

        if ($localUser && ! empty($localUser->address)) {
            return $localUser->address;
        }

        return $centralUser->country ?? null;
    }

    // ---------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------

    private function deliverInApp(array $contact, string $subject, string $body, ?array $attachment, ?User $admin, $membershipId): array
    {
        $user = $contact['local_user_id'] ? User::find($contact['local_user_id']) : null;

        if (! $user) {
            // The customer has not visited this site yet: create the local account their central login maps to (same as CentralAuthTokenMiddleware).
            $base = Str::slug(Str::before($contact['email'], '@'), '_') ?: 'user';
            $username = $base;
            $n = 1;
            while (User::where('username', $username)->exists()) {
                $username = $base . '_' . (++$n);
            }
            $role = $contact['account_type'] === 'business' ? 'business' : 'customer';
            $user = User::create([
                'name' => $contact['name'],
                'email' => $contact['email'],
                'username' => $username,
                'password' => bcrypt(Str::random(32)),
                'role' => $role,
                'phone' => $contact['phone'],
            ]);
            try {
                \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
                $user->assignRole($role);
            } catch (\Throwable) {
            }
        }

        $text = $body;
        if ($attachment) {
            $tag = '[attachment:' . json_encode([
                'name' => $attachment['name'],
                'size' => $attachment['size'],
                'type' => str_starts_with($attachment['mime'], 'image/') ? 'image' : (str_contains($attachment['mime'], 'pdf') ? 'pdf' : 'document'),
                'url' => $attachment['url'],
            ]) . ']';
            $text .= "\n\n" . $tag;
        }

        $now = Carbon::now();
        $conversation = DB::transaction(function () use ($user, $subject, $text, $admin, $membershipId, $contact, $now) {
            $conversation = EcommerceConversation::create([
                'user_id' => $user->id,
                'subject' => $subject,
                'status' => 'open',
                'linked_type' => 'membership',
                'linked_id' => $this->numericId($membershipId),
                'linked_label' => $contact['membership']['plan_name'] ?? 'Membership',
                'last_admin_message_at' => $now,
                'last_message_at' => $now,
            ]);
            $conversation->messages()->create([
                'sender_id' => $admin?->id,
                'sender_type' => 'admin',
                'message' => $text,
            ]);
            return $conversation;
        });

        return ['conversation_id' => $conversation->id, 'user_id' => $user->id];
    }

    /**
     * Used by the membership transactions screen (refund confirmations, receipt resends).
     * $attachment: ['name','absolute_path','mime','url'] or null. $cc: extra recipient or null.
     */
    public function sendTransactionalEmail(array $contact, string $subject, string $body, ?array $attachment, ?User $admin, ?string $cc = null, ?string $eventKey = null): EmailNotificationLog
    {
        return $this->deliverEmail($contact, $subject, $body, $attachment, $admin, $cc, $eventKey);
    }

    private function deliverEmail(array $contact, string $subject, string $body, ?array $attachment, ?User $admin, ?string $cc = null, ?string $eventKey = null): EmailNotificationLog
    {
        $recipient = $contact['email'];
        $log = EmailNotificationLog::create([
            'event_key' => $eventKey ?: 'admin_membership_message',
            'recipient_email' => $recipient,
            'recipient_type' => 'customer',
            'subject' => $subject,
            'status' => 'pending',
            'payload' => [
                'subject' => $subject,
                'message' => $body,
                'attachment' => $attachment['url'] ?? null,
                'sent_by' => $admin?->email,
            ],
        ]);

        try {
            $setting = app(EmailNotificationService::class)->setting();

            try {
                Mail::purge('smtp');
            } catch (\Throwable) {
            }
            $fromName = ($setting->from_name && strtolower($setting->from_name) !== 'laravel') ? $setting->from_name : 'Mecarvi Embroidery';
            $fromEmail = $setting->from_email ?: env('MAIL_FROM_ADDRESS', config('mail.from.address', 'noreply@mecarvi.com'));
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', $setting->smtp_host ?: env('MAIL_HOST', '127.0.0.1'));
            Config::set('mail.mailers.smtp.port', $setting->smtp_port ?: env('MAIL_PORT', 587));
            Config::set('mail.mailers.smtp.encryption', $setting->smtp_encryption ?: env('MAIL_ENCRYPTION'));
            Config::set('mail.mailers.smtp.username', $setting->smtp_username ?: env('MAIL_USERNAME'));
            Config::set('mail.mailers.smtp.password', $setting->getDecryptedSmtpPassword() ?: env('MAIL_PASSWORD'));
            Config::set('mail.from.address', $fromEmail);
            Config::set('mail.from.name', $fromName);

            $html = $this->renderEmail($contact['name'], $subject, $body, $fromName);
            $replyTo = filter_var($setting->reply_to_email ?? null, FILTER_VALIDATE_EMAIL) ? $setting->reply_to_email : null;

            Mail::send([], [], function ($message) use ($recipient, $subject, $fromEmail, $fromName, $html, $attachment, $replyTo, $cc) {
                $message->to($recipient)->from($fromEmail, $fromName)->subject($subject)->html($html);
                if ($cc && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                    $message->cc($cc);
                }
                if ($replyTo) {
                    $message->replyTo($replyTo);
                }
                if ($attachment && is_file($attachment['absolute_path'])) {
                    $message->attach($attachment['absolute_path'], ['as' => $attachment['name'], 'mime' => $attachment['mime']]);
                }
            });

            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error('Membership message email failed: ' . $e->getMessage(), ['recipient' => $recipient]);
        }

        return $log->fresh();
    }

    private function renderEmail(string $name, string $subject, string $body, string $siteName): string
    {
        return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:32px 16px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="padding:24px 32px;border-bottom:1px solid #e5e7eb;font-size:16px;font-weight:800;">' . e($siteName) . '</td></tr>'
            . '<tr><td style="padding:32px;"><h1 style="margin:0 0 18px;font-size:22px;line-height:1.3;">' . e($subject) . '</h1>'
            . '<p style="margin:0 0 16px;font-size:15px;color:#374151;">Hi ' . e($name) . ',</p>'
            . '<div style="font-size:15px;line-height:1.7;color:#374151;">' . self::formatBody($body) . '</div></td></tr>'
            . '<tr><td style="padding:22px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">You received this message because you are a ' . e($siteName) . ' member.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Converts the popup's lightweight formatting (**bold**, *italic*, __underline__, [text](url), "- " and "1. " lists)
     * into safe HTML. Everything is escaped first, so no raw HTML from the admin reaches the email.
     */
    public static function formatBody(string $text): string
    {
        $inline = function (string $line): string {
            $line = e($line);
            $line = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', fn ($m) => '<a href="' . $m[2] . '" style="color:#ea580c;">' . $m[1] . '</a>', $line);
            $line = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $line);
            $line = preg_replace('/__(.+?)__/s', '<u>$1</u>', $line);
            $line = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $line);
            return $line;
        };

        $html = '';
        $listType = null;
        $paragraph = [];
        $flushParagraph = function () use (&$paragraph, &$html) {
            if ($paragraph) {
                $html .= '<p style="margin:0 0 14px;">' . implode('<br>', $paragraph) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = function () use (&$listType, &$html) {
            if ($listType) {
                $html .= "</{$listType}>";
                $listType = null;
            }
        };

        foreach (preg_split('/\R/', $text) as $raw) {
            if (preg_match('/^\s*[-•]\s+(.*)$/u', $raw, $m)) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $closeList();
                    $html .= '<ul style="margin:0 0 14px;padding-left:22px;">';
                    $listType = 'ul';
                }
                $html .= '<li>' . $inline($m[1]) . '</li>';
            } elseif (preg_match('/^\s*\d+[.)]\s+(.*)$/', $raw, $m)) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $closeList();
                    $html .= '<ol style="margin:0 0 14px;padding-left:22px;">';
                    $listType = 'ol';
                }
                $html .= '<li>' . $inline($m[1]) . '</li>';
            } elseif (trim($raw) === '') {
                $closeList();
                $flushParagraph();
            } else {
                $closeList();
                $paragraph[] = $inline($raw);
            }
        }
        $closeList();
        $flushParagraph();

        return $html;
    }
}
