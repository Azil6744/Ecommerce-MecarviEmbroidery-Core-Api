<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipPageContent extends Model
{
    use HasFactory;

    /** Sections stored as JSON. Objects are merged key-by-key with the defaults, lists replace them. */
    public const OBJECT_SECTIONS = ['hero', 'why_join', 'plan_section', 'benefits_section', 'bottom_cta'];
    public const LIST_SECTIONS = ['hero_features', 'plans', 'benefits'];

    protected $fillable = [
        'backgrounds',
        'hero',
        'hero_features',
        'why_join',
        'stats',
        'plan_section',
        'plans',
        'benefits_section',
        'benefits',
        'bottom_cta',
        'faq_section',
        'faqs',
        'support_section',
    ];

    protected $casts = [
        'backgrounds' => 'array',
        'hero' => 'array',
        'hero_features' => 'array',
        'why_join' => 'array',
        'stats' => 'array',
        'plan_section' => 'array',
        'plans' => 'array',
        'benefits_section' => 'array',
        'benefits' => 'array',
        'bottom_cta' => 'array',
        'faq_section' => 'array',
        'faqs' => 'array',
        'support_section' => 'array',
    ];

    /**
     * Content of the public /membership page until an admin edits it.
     *
     * `plans` only holds how a plan is presented (order, colours, wording). Price, billing
     * cycle and availability always come from the live subscription plan with that code.
     */
    public static function defaults(): array
    {
        return [
            'hero' => [
                'eyebrow' => 'MECARVI MEMBERSHIP',
                'titleLine1' => 'Membership Built',
                'titleLine2' => 'for More Value',
                'description' => 'Join the Mecarvi Embroidery membership and get more than great embroidery — enjoy member pricing, rewards, faster service and exclusive deals designed to give your business more value all year long.',
                'primaryButtonText' => 'Join Now',
                'secondaryButtonText' => 'View Plans',
                'image' => '/assets/membership/isolated_membership_card.png',
                'imageAlt' => 'Mecarvi Embroidery membership card',
            ],
            'hero_features' => [
                ['title' => 'Lower Overall Costs', 'copy' => 'Save more with exclusive member pricing and credits.', 'icon' => 'coins', 'color' => 'pink'],
                ['title' => 'Faster Service', 'copy' => 'Get priority processing and quicker turnaround.', 'icon' => 'stopwatch', 'color' => 'blue'],
                ['title' => 'Grow Your Business', 'copy' => 'Access ongoing savings and tools to support expansion.', 'icon' => 'rocket', 'color' => 'green'],
                ['title' => 'Exclusive Privileges', 'copy' => 'Enjoy member-only deals, early access and special offers.', 'icon' => 'gift', 'color' => 'orange'],
            ],
            'why_join' => [
                'title' => 'Why Members Join',
                'items' => [
                    'Get more value on every embroidery order.',
                    'Enjoy faster, more prioritized service.',
                    'Unlock member-only savings and rewards.',
                ],
            ],
            'plan_section' => [
                'title' => 'Membership Plans',
                'subtitle' => 'Choose the membership that fits your business needs and enjoy exclusive value, rewards and premium service.',
            ],
            // Empty = show every plan the admin has listed, with the plan's own name, text and colour.
            'plans' => [],
            'benefits_section' => [
                'title' => 'Membership Benefits',
                'subtitle' => 'A complete set of member benefits designed to help you save more, get more and grow faster.',
            ],
            'benefits' => [
                ['title' => 'Exclusive Pricing', 'copy' => 'Enjoy member-only rates on embroidery services and bulk orders.', 'icon' => 'tag', 'color' => 'pink'],
                ['title' => 'Priority Turnaround', 'copy' => 'Move your orders to the front of the production queue for faster completion.', 'icon' => 'lightning', 'color' => 'orange'],
                ['title' => 'Special Access', 'copy' => 'Be the first to know about new services, product releases and upcoming promotions.', 'icon' => 'layers', 'color' => 'blue'],
                ['title' => 'Reward Program', 'copy' => 'Earn points on every purchase and redeem them for savings on future orders.', 'icon' => 'coins', 'color' => 'green'],
                ['title' => 'Dedicated Assistance', 'copy' => 'Work with a knowledgeable team that understands your business needs.', 'icon' => 'headset', 'color' => 'purple'],
                ['title' => 'Member Events', 'copy' => 'Get invitations to exclusive sales, product showcases and special campaigns.', 'icon' => 'calendar', 'color' => 'magenta'],
            ],
            'bottom_cta' => [
                'title' => 'Ready to Unlock Exclusive Member Benefits?',
                'description' => 'Join Mecarvi Embroidery today and start saving, earning rewards, and growing your business.',
                'buttonText' => 'Join Mecarvi Membership',
            ],
        ];
    }

    /** Saved content with the defaults filled in for anything the admin has not set. */
    public static function present(?self $content): array
    {
        $defaults = self::defaults();
        $out = ['id' => $content?->id];

        foreach (self::OBJECT_SECTIONS as $section) {
            $saved = is_array($content?->{$section}) ? $content->{$section} : [];
            $out[$section] = array_merge($defaults[$section], $saved);
        }

        foreach (self::LIST_SECTIONS as $section) {
            $saved = $content?->{$section};
            // null = never edited; an empty list is a deliberate choice by the admin.
            $out[$section] = is_array($saved) ? array_values($saved) : $defaults[$section];
        }

        $out['updated_at'] = $content?->updated_at;

        return $out;
    }
}
