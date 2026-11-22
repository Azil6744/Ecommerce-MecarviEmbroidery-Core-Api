<?php

namespace App\Services\Moderation;

/**
 * Screens customer-written text (reviews, questions, answers, report comments) for spam,
 * offensive language and personal information before it is saved.
 */
class ContentFilter
{
    /** Whole-word matches, lower case. Kept short on purpose: admins still moderate everything. */
    private const BLOCKED_WORDS = [
        'fuck', 'fucking', 'fucker', 'motherfucker', 'shit', 'bullshit', 'bitch', 'bastard', 'asshole',
        'dick', 'cunt', 'whore', 'slut', 'retard', 'nigger', 'nigga', 'faggot', 'fag', 'wanker', 'twat',
    ];

    private const SPAM_PHRASES = [
        'buy now', 'click here', 'free money', 'work from home', 'make money fast', 'visit my',
        'check out my', 'promo code', 'whatsapp me', 'telegram me', 'dm me',
        'bitcoin', 'casino', 'viagra', 'loan offer',
    ];

    /**
     * Returns null when every text passes, otherwise a message for the customer.
     *
     * @param  array<string, ?string>  $fields  field label => text
     */
    public static function check(array $fields): ?string
    {
        foreach ($fields as $label => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            if ($reason = self::problem($text)) {
                return ucfirst($label) . ': ' . $reason;
            }
        }

        return null;
    }

    private static function problem(string $text): ?string
    {
        $lower = mb_strtolower($text);

        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text)) {
            return 'please remove email addresses. Personal information is not allowed.';
        }

        // 7+ digits, ignoring spaces, dots, dashes and brackets, looks like a phone or card number.
        if (preg_match('/(?:\+?\d[\s\-.()]*){7,}/', $text)) {
            return 'please remove phone numbers or other long numbers. Personal information is not allowed.';
        }

        if (preg_match('~(https?://|www\.)|\b[a-z0-9\-]+\.(com|net|org|io|xyz|shop|store|biz|info|ru|cn)\b~i', $text)) {
            return 'links and website addresses are not allowed.';
        }

        foreach (self::BLOCKED_WORDS as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $lower)) {
                return 'please remove offensive language.';
            }
        }

        foreach (self::SPAM_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'this looks like spam or advertising.';
            }
        }

        if (preg_match('/(.)\1{7,}/u', $text)) {
            return 'this looks like spam (repeated characters).';
        }

        $letters = preg_replace('/[^a-z]/i', '', $text);
        if (strlen($letters) >= 20 && strtoupper($letters) === $letters) {
            return 'please do not write in all capital letters.';
        }

        return null;
    }
}
