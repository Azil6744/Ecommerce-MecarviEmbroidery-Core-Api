<?php

namespace App\Http\Controllers\Api\Admin\footer;

use App\Http\Controllers\Controller;
use App\Models\FooterContent;
use App\Models\FooterLink;
use App\Models\FooterPaymentMethod;
use App\Traits\BroadcastsContentUpdates;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FooterController extends Controller
{
    use BroadcastsContentUpdates;

    /**
     * Get full footer content (Public).
     */
    public function index()
    {
        try {
            $content = FooterContent::first();
            if (! $content) {
                return response()->json([
                    'success' => true,
                    'data' => ['footer' => $this->defaultFooterStructure()],
                ], 200);
            }

            $companyLinks = FooterLink::ofType(FooterLink::TYPE_COMPANY)->ordered()->get();
            $supportLinks = FooterLink::ofType(FooterLink::TYPE_SUPPORT)->ordered()->get();
            $policyLinks = FooterLink::ofType(FooterLink::TYPE_POLICY)->ordered()->get();
            $policyCenterLinks = FooterLink::ofType(FooterLink::TYPE_POLICY_CENTER)->ordered()->get();
            $brandsLinks = FooterLink::ofType(FooterLink::TYPE_OUR_BRANDS)->ordered()->get();
            $paymentMethods = FooterPaymentMethod::ordered()->get();

            $data = [
                'contact_info' => [
                    'section_heading' => $content->contact_section_heading ?? 'CONTACT INFO',
                    'phone' => $content->phone ?? null,
                    'fax' => $content->fax ?? null,
                    'email' => $content->email ?? null,
                    'corporate_address' => $content->corporate_address ?? $content->company_address ?? null,
                    'company_address' => $content->company_address ?? $content->corporate_address ?? null,
                    'hours_mon_fri' => $content->hours_mon_fri ?? null,
                    'hours_sat' => $content->hours_sat ?? null,
                    'hours_sun_holidays' => $content->hours_sun_holidays ?? null,
                    'chat_title' => $content->chat_title ?? null,
                    'chat_subtitle' => $content->chat_subtitle ?? null,
                ],
                'company' => [
                    'section_heading' => $content->company_section_heading ?? 'COMPANY',
                    'links' => $companyLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
                ],
                'support' => [
                    'section_heading' => $content->support_section_heading ?? 'SUPPORT',
                    'links' => $supportLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
                ],
                'policy' => [
                    'section_heading' => $content->policy_section_heading ?? 'POLICY',
                    'links' => $policyLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
                ],
                'policy_center' => [
                    'section_heading' => $content->policy_center_section_heading ?? 'POLICY CENTER',
                    'links' => $policyCenterLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
                ],
                'our_brands' => [
                    'section_heading' => $content->our_brands_section_heading ?? 'OUR BRANDS',
                    'links' => $brandsLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
                ],
                'social_links' => [
                    'section_heading' => $content->social_links_section_heading ?? 'SOCIAL LINKS',
                    'facebook_url' => $content->facebook_url ?? null,
                    'twitter_url' => $content->twitter_url ?? null,
                    'instagram_url' => $content->instagram_url ?? null,
                    'linkedin_url' => $content->linkedin_url ?? null,
                    'tiktok_url' => $content->tiktok_url ?? null,
                    'youtube_url' => $content->youtube_url ?? null,
                ],
                'payment_methods' => [
                    'section_heading' => $content->payment_methods_section_heading ?? 'PAYMENT METHODS',
                    'items' => $paymentMethods->map(fn ($pm) => [
                        'id' => $pm->id,
                        'name' => $pm->name,
                        'image_url' => $pm->image_url,
                        'is_enabled' => $pm->is_enabled,
                        'sort_order' => $pm->sort_order,
                    ]),
                ],
                'copyright_text' => $content->copyright_text ?? null,
            ];

            return response()->json([
                'success' => true,
                'data' => ['footer' => $data],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch footer',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Save full footer (Admin only).
     */
    public function store(Request $request)
    {
        try {
            $currentUser = $request->user();
            if (! $currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can manage footer.',
                ], 403);
            }

            // Normalize empty strings to null for optional URL fields
            $slInput = $request->input('social_links', []);
            if (is_array($slInput)) {
                foreach (['facebook_url', 'twitter_url', 'instagram_url', 'linkedin_url', 'tiktok_url', 'youtube_url'] as $key) {
                    if (isset($slInput[$key]) && $slInput[$key] === '') {
                        $slInput[$key] = null;
                    }
                }
                $request->merge(['social_links' => $slInput]);
            }

            $validated = $request->validate([
                'contact_info' => ['sometimes', 'array'],
                'contact_info.section_heading' => ['nullable', 'string', 'max:255'],
                'contact_info.phone' => ['nullable', 'string', 'max:100'],
                'contact_info.fax' => ['nullable', 'string', 'max:100'],
                'contact_info.email' => ['nullable', 'string', 'email', 'max:255'],
                'contact_info.corporate_address' => ['nullable', 'string', 'max:500'],
                'contact_info.company_address' => ['nullable', 'string', 'max:500'],
                'contact_info.hours_mon_fri' => ['nullable', 'string', 'max:255'],
                'contact_info.hours_sat' => ['nullable', 'string', 'max:255'],
                'contact_info.hours_sun_holidays' => ['nullable', 'string', 'max:255'],
                'contact_info.chat_title' => ['nullable', 'string', 'max:255'],
                'contact_info.chat_subtitle' => ['nullable', 'string', 'max:255'],
                'company' => ['sometimes', 'array'],
                'company.section_heading' => ['nullable', 'string', 'max:255'],
                'company.links' => ['sometimes', 'array'],
                'company.links.*.text' => ['required_with:company.links', 'string', 'max:255'],
                'company.links.*.path' => ['required_with:company.links', 'string', 'max:500'],
                'company.links.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'support' => ['sometimes', 'array'],
                'support.section_heading' => ['nullable', 'string', 'max:255'],
                'support.links' => ['sometimes', 'array'],
                'support.links.*.text' => ['required_with:support.links', 'string', 'max:255'],
                'support.links.*.path' => ['required_with:support.links', 'string', 'max:500'],
                'support.links.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'policy' => ['sometimes', 'array'],
                'policy.section_heading' => ['nullable', 'string', 'max:255'],
                'policy.links' => ['sometimes', 'array'],
                'policy.links.*.text' => ['required_with:policy.links', 'string', 'max:255'],
                'policy.links.*.path' => ['required_with:policy.links', 'string', 'max:500'],
                'policy.links.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'policy.links.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'policy_center' => ['sometimes', 'array'],
                'policy_center.section_heading' => ['nullable', 'string', 'max:255'],
                'policy_center.links' => ['sometimes', 'array'],
                'policy_center.links.*.text' => ['required_with:policy_center.links', 'string', 'max:255'],
                'policy_center.links.*.path' => ['required_with:policy_center.links', 'string', 'max:500'],
                'policy_center.links.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'our_brands' => ['sometimes', 'array'],
                'our_brands.section_heading' => ['nullable', 'string', 'max:255'],
                'our_brands.links' => ['sometimes', 'array'],
                'our_brands.links.*.text' => ['required_with:our_brands.links', 'string', 'max:255'],
                'our_brands.links.*.path' => ['required_with:our_brands.links', 'string', 'max:500'],
                'our_brands.links.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'social_links' => ['sometimes', 'array'],
                'social_links.section_heading' => ['nullable', 'string', 'max:255'],
                'social_links.facebook_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.twitter_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.instagram_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.linkedin_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.linkedin_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.tiktok_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.youtube_url' => ['nullable', 'string', 'url', 'max:500'],
                'payment_methods' => ['sometimes', 'array'],
                'payment_methods.section_heading' => ['nullable', 'string', 'max:255'],
                'payment_methods.items' => ['sometimes', 'array'],
                'payment_methods.items.*.name' => ['required_with:payment_methods.items', 'string', 'max:255'],
                'payment_methods.items.*.image_url' => ['nullable', 'string', 'max:51200'],
                'payment_methods.items.*.is_enabled' => ['sometimes', 'boolean'],
                'payment_methods.items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
                'copyright_text' => ['nullable', 'string'],
            ]);

            $content = FooterContent::first();
            if (! $content) {
                $content = new FooterContent;
            }

            $ci = $request->input('contact_info', []);
            if (array_key_exists('section_heading', $ci)) {
                $content->contact_section_heading = $ci['section_heading'];
            }
            if (array_key_exists('phone', $ci)) {
                $content->phone = $ci['phone'];
            }
            if (array_key_exists('fax', $ci)) {
                $content->fax = $ci['fax'];
            }
            if (array_key_exists('email', $ci)) {
                $content->email = $ci['email'];
            }
            if (array_key_exists('corporate_address', $ci)) {
                $content->corporate_address = $ci['corporate_address'];
                $content->company_address = $ci['corporate_address'];
            } elseif (array_key_exists('company_address', $ci)) {
                $content->company_address = $ci['company_address'];
                $content->corporate_address = $ci['company_address'];
            }
            if (array_key_exists('hours_mon_fri', $ci)) {
                $content->hours_mon_fri = $ci['hours_mon_fri'];
            }
            if (array_key_exists('hours_sat', $ci)) {
                $content->hours_sat = $ci['hours_sat'];
            }
            if (array_key_exists('hours_sun_holidays', $ci)) {
                $content->hours_sun_holidays = $ci['hours_sun_holidays'];
            }
            if (array_key_exists('chat_title', $ci)) {
                $content->chat_title = $ci['chat_title'];
            }
            if (array_key_exists('chat_subtitle', $ci)) {
                $content->chat_subtitle = $ci['chat_subtitle'];
            }

            if ($request->has('company')) {
                $co = $request->input('company', []);
                $content->company_section_heading = $co['section_heading'] ?? $content->company_section_heading;
            }
            if ($request->has('support')) {
                $su = $request->input('support', []);
                $content->support_section_heading = $su['section_heading'] ?? $content->support_section_heading;
            }
            if ($request->has('policy')) {
                $po = $request->input('policy', []);
                $content->policy_section_heading = $po['section_heading'] ?? $content->policy_section_heading;
            }
            if ($request->has('policy_center')) {
                $pc = $request->input('policy_center', []);
                $content->policy_center_section_heading = $pc['section_heading'] ?? $content->policy_center_section_heading;
            }
            if ($request->has('our_brands')) {
                $ob = $request->input('our_brands', []);
                $content->our_brands_section_heading = $ob['section_heading'] ?? $content->our_brands_section_heading;
            }
            $sl = $request->input('social_links', []);
            if (array_key_exists('section_heading', $sl)) {
                $content->social_links_section_heading = $sl['section_heading'];
            }
            if (array_key_exists('facebook_url', $sl)) {
                $content->facebook_url = $sl['facebook_url'];
            }
            if (array_key_exists('twitter_url', $sl)) {
                $content->twitter_url = $sl['twitter_url'];
            }
            if (array_key_exists('instagram_url', $sl)) {
                $content->instagram_url = $sl['instagram_url'];
            }
            if (array_key_exists('linkedin_url', $sl)) {
                $content->linkedin_url = $sl['linkedin_url'];
            }
            if (array_key_exists('tiktok_url', $sl)) {
                $content->tiktok_url = $sl['tiktok_url'];
            }
            if (array_key_exists('youtube_url', $sl)) {
                $content->youtube_url = $sl['youtube_url'];
            }
            if ($request->has('payment_methods')) {
                $content->payment_methods_section_heading = $request->input('payment_methods.section_heading', $content->payment_methods_section_heading);
            }
            if ($request->has('copyright_text')) {
                $content->copyright_text = $request->input('copyright_text');
            }

            $content->save();

            if ($request->has('company.links')) {
                FooterLink::ofType(FooterLink::TYPE_COMPANY)->delete();
                foreach ($request->input('company.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_COMPANY,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }

            if ($request->has('support.links')) {
                FooterLink::ofType(FooterLink::TYPE_SUPPORT)->delete();
                foreach ($request->input('support.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_SUPPORT,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }

            if ($request->has('policy.links')) {
                FooterLink::ofType(FooterLink::TYPE_POLICY)->delete();
                foreach ($request->input('policy.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_POLICY,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }

            if ($request->has('policy_center.links')) {
                FooterLink::ofType(FooterLink::TYPE_POLICY_CENTER)->delete();
                foreach ($request->input('policy_center.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_POLICY_CENTER,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }

            if ($request->has('our_brands.links')) {
                FooterLink::ofType(FooterLink::TYPE_OUR_BRANDS)->delete();
                foreach ($request->input('our_brands.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_OUR_BRANDS,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }

            if ($request->has('payment_methods.items')) {
                FooterPaymentMethod::query()->delete();
                foreach ($request->input('payment_methods.items', []) as $index => $item) {
                    FooterPaymentMethod::create([
                        'name' => $item['name'] ?? '',
                        'image_url' => $item['image_url'] ?? null,
                        'is_enabled' => isset($item['is_enabled']) ? (bool) $item['is_enabled'] : true,
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }

            $this->broadcastContentUpdate('footer', 'updated', []);

            return response()->json([
                'success' => true,
                'message' => 'Footer saved successfully',
                'data' => ['footer' => $this->buildFooterData()],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save footer',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Update footer by ID (Admin only). Same body as store(); only provided sections are updated.
     */
    public function update(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            if (! $currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can manage footer.',
                ], 403);
            }

            $content = FooterContent::find($id);
            if (! $content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Footer not found.',
                ], 404);
            }

            $slInput = $request->input('social_links', []);
            if (is_array($slInput)) {
                foreach (['facebook_url', 'twitter_url', 'instagram_url', 'linkedin_url', 'tiktok_url', 'youtube_url'] as $key) {
                    if (isset($slInput[$key]) && $slInput[$key] === '') {
                        $slInput[$key] = null;
                    }
                }
                $request->merge(['social_links' => $slInput]);
            }

            $request->validate([
                'contact_info' => ['sometimes', 'array'],
                'contact_info.section_heading' => ['nullable', 'string', 'max:255'],
                'contact_info.phone' => ['nullable', 'string', 'max:100'],
                'contact_info.fax' => ['nullable', 'string', 'max:100'],
                'contact_info.email' => ['nullable', 'string', 'email', 'max:255'],
                'contact_info.corporate_address' => ['nullable', 'string', 'max:500'],
                'contact_info.company_address' => ['nullable', 'string', 'max:500'],
                'contact_info.hours_mon_fri' => ['nullable', 'string', 'max:255'],
                'contact_info.hours_sat' => ['nullable', 'string', 'max:255'],
                'contact_info.hours_sun_holidays' => ['nullable', 'string', 'max:255'],
                'contact_info.chat_title' => ['nullable', 'string', 'max:255'],
                'contact_info.chat_subtitle' => ['nullable', 'string', 'max:255'],
                'company' => ['sometimes', 'array'],
                'company.section_heading' => ['nullable', 'string', 'max:255'],
                'company.links' => ['sometimes', 'array'],
                'company.links.*.text' => ['required_with:company.links', 'string', 'max:255'],
                'company.links.*.path' => ['required_with:company.links', 'string', 'max:500'],
                'support' => ['sometimes', 'array'],
                'support.section_heading' => ['nullable', 'string', 'max:255'],
                'support.links' => ['sometimes', 'array'],
                'support.links.*.text' => ['required_with:support.links', 'string', 'max:255'],
                'support.links.*.path' => ['required_with:support.links', 'string', 'max:500'],
                'policy' => ['sometimes', 'array'],
                'policy.section_heading' => ['nullable', 'string', 'max:255'],
                'policy.links' => ['sometimes', 'array'],
                'policy.links.*.text' => ['required_with:policy.links', 'string', 'max:255'],
                'policy.links.*.path' => ['required_with:policy.links', 'string', 'max:500'],
                'policy_center' => ['sometimes', 'array'],
                'policy_center.section_heading' => ['nullable', 'string', 'max:255'],
                'policy_center.links' => ['sometimes', 'array'],
                'policy_center.links.*.text' => ['required_with:policy_center.links', 'string', 'max:255'],
                'policy_center.links.*.path' => ['required_with:policy_center.links', 'string', 'max:500'],
                'our_brands' => ['sometimes', 'array'],
                'our_brands.section_heading' => ['nullable', 'string', 'max:255'],
                'our_brands.links' => ['sometimes', 'array'],
                'our_brands.links.*.text' => ['required_with:our_brands.links', 'string', 'max:255'],
                'our_brands.links.*.path' => ['required_with:our_brands.links', 'string', 'max:500'],
                'social_links' => ['sometimes', 'array'],
                'social_links.section_heading' => ['nullable', 'string', 'max:255'],
                'social_links.facebook_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.twitter_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.instagram_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.linkedin_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.linkedin_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.tiktok_url' => ['nullable', 'string', 'url', 'max:500'],
                'social_links.youtube_url' => ['nullable', 'string', 'url', 'max:500'],
                'payment_methods' => ['sometimes', 'array'],
                'payment_methods.section_heading' => ['nullable', 'string', 'max:255'],
                'payment_methods.items' => ['sometimes', 'array'],
                'payment_methods.items.*.name' => ['required_with:payment_methods.items', 'string', 'max:255'],
                'payment_methods.items.*.image_url' => ['nullable', 'string', 'max:51200'],
                'payment_methods.items.*.is_enabled' => ['sometimes', 'boolean'],
                'copyright_text' => ['nullable', 'string'],
            ]);

            $ci = $request->input('contact_info', []);
            $contactMap = [
                'section_heading' => 'contact_section_heading',
                'phone' => 'phone',
                'fax' => 'fax',
                'email' => 'email',
                'corporate_address' => 'corporate_address',
                'company_address' => 'company_address',
                'hours_mon_fri' => 'hours_mon_fri',
                'hours_sat' => 'hours_sat',
                'hours_sun_holidays' => 'hours_sun_holidays',
                'chat_title' => 'chat_title',
                'chat_subtitle' => 'chat_subtitle',
            ];
            foreach ($contactMap as $inputKey => $dbColumn) {
                if (array_key_exists($inputKey, $ci)) {
                    $content->$dbColumn = $ci[$inputKey];
                }
            }
            if (array_key_exists('corporate_address', $ci) && !array_key_exists('company_address', $ci)) {
                $content->company_address = $ci['corporate_address'];
            } elseif (array_key_exists('company_address', $ci) && !array_key_exists('corporate_address', $ci)) {
                $content->corporate_address = $ci['company_address'];
            }
            if ($request->has('company')) {
                $content->company_section_heading = $request->input('company.section_heading', $content->company_section_heading);
            }
            if ($request->has('support')) {
                $content->support_section_heading = $request->input('support.section_heading', $content->support_section_heading);
            }
            if ($request->has('policy')) {
                $content->policy_section_heading = $request->input('policy.section_heading', $content->policy_section_heading);
            }
            if ($request->has('policy_center')) {
                $content->policy_center_section_heading = $request->input('policy_center.section_heading', $content->policy_center_section_heading);
            }
            if ($request->has('our_brands')) {
                $content->our_brands_section_heading = $request->input('our_brands.section_heading', $content->our_brands_section_heading);
            }
            $sl = $request->input('social_links', []);
            foreach (['section_heading', 'facebook_url', 'twitter_url', 'instagram_url', 'linkedin_url', 'tiktok_url', 'youtube_url'] as $key) {
                if (array_key_exists($key, $sl)) {
                    $content->{($key === 'section_heading' ? 'social_links_section_heading' : $key)} = $sl[$key];
                }
            }
            if ($request->has('payment_methods')) {
                $content->payment_methods_section_heading = $request->input('payment_methods.section_heading', $content->payment_methods_section_heading);
            }
            if ($request->has('copyright_text')) {
                $content->copyright_text = $request->input('copyright_text');
            }
            $content->save();

            if ($request->has('company.links')) {
                FooterLink::ofType(FooterLink::TYPE_COMPANY)->delete();
                foreach ($request->input('company.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_COMPANY,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }
            if ($request->has('support.links')) {
                FooterLink::ofType(FooterLink::TYPE_SUPPORT)->delete();
                foreach ($request->input('support.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_SUPPORT,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }
            if ($request->has('policy.links')) {
                FooterLink::ofType(FooterLink::TYPE_POLICY)->delete();
                foreach ($request->input('policy.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_POLICY,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }
            if ($request->has('policy_center.links')) {
                FooterLink::ofType(FooterLink::TYPE_POLICY_CENTER)->delete();
                foreach ($request->input('policy_center.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_POLICY_CENTER,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }
            if ($request->has('our_brands.links')) {
                FooterLink::ofType(FooterLink::TYPE_OUR_BRANDS)->delete();
                foreach ($request->input('our_brands.links', []) as $index => $item) {
                    FooterLink::create([
                        'type' => FooterLink::TYPE_OUR_BRANDS,
                        'label' => $item['text'] ?? $item['label'] ?? '',
                        'path' => $item['path'] ?? '',
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }
            if ($request->has('payment_methods.items')) {
                FooterPaymentMethod::query()->delete();
                foreach ($request->input('payment_methods.items', []) as $index => $item) {
                    FooterPaymentMethod::create([
                        'name' => $item['name'] ?? '',
                        'image_url' => $item['image_url'] ?? null,
                        'is_enabled' => isset($item['is_enabled']) ? (bool) $item['is_enabled'] : true,
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
                }
            }

            $this->broadcastContentUpdate('footer', 'updated', ['id' => $content->id]);

            return response()->json([
                'success' => true,
                'message' => 'Footer updated successfully',
                'data' => ['footer' => $this->buildFooterData()],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update footer',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Delete footer content and all links and payment methods (Admin only).
     */
    public function destroy(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            if (! $currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can delete footer.',
                ], 403);
            }

            $content = FooterContent::find($id);
            if (! $content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Footer not found.',
                ], 404);
            }

            FooterLink::query()->delete();
            FooterPaymentMethod::query()->delete();
            $content->delete();

            $this->broadcastContentUpdate('footer', 'deleted', ['id' => (int) $id]);

            return response()->json([
                'success' => true,
                'message' => 'Footer deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete footer',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Delete a single field from footer content (set to null). Admin only.
     */
    public function deleteField(Request $request, $id, $field)
    {
        try {
            $currentUser = $request->user();
            if (! $currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can delete footer fields.',
                ], 403);
            }

            $content = FooterContent::find($id);
            if (! $content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Footer not found.',
                ], 404);
            }

            $allowedFields = [
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
                'policy_center_section_heading',
                'our_brands_section_heading',
                'social_links_section_heading',
                'facebook_url',
                'twitter_url',
                'instagram_url',
                'linkedin_url',
                'linkedin_url',
                'tiktok_url',
                'youtube_url',
                'payment_methods_section_heading',
                'copyright_text',
            ];

            if (! in_array($field, $allowedFields)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid field name. Allowed: ' . implode(', ', $allowedFields),
                ], 422);
            }

            $content->$field = null;
            $content->save();
            $content->refresh();

            $this->broadcastContentUpdate('footer', 'updated', ['id' => $content->id, 'field' => $field]);

            return response()->json([
                'success' => true,
                'message' => 'Field deleted successfully',
                'data' => ['footer' => $this->buildFooterData()],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete field',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Delete a single footer link (Admin only).
     */
    public function destroyLink(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            if (! $currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can delete footer links.',
                ], 403);
            }

            $link = FooterLink::find($id);
            if (! $link) {
                return response()->json([
                    'success' => false,
                    'message' => 'Footer link not found.',
                ], 404);
            }

            $link->delete();
            $this->broadcastContentUpdate('footer', 'updated', ['link_id' => (int) $id]);

            return response()->json([
                'success' => true,
                'message' => 'Footer link deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete footer link',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Delete a single footer payment method (Admin only).
     */
    public function destroyPaymentMethod(Request $request, $id)
    {
        try {
            $currentUser = $request->user();
            if (! $currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can delete footer payment methods.',
                ], 403);
            }

            $pm = FooterPaymentMethod::find($id);
            if (! $pm) {
                return response()->json([
                    'success' => false,
                    'message' => 'Footer payment method not found.',
                ], 404);
            }

            $pm->delete();
            $this->broadcastContentUpdate('footer', 'updated', ['payment_method_id' => (int) $id]);

            return response()->json([
                'success' => true,
                'message' => 'Footer payment method deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete footer payment method',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    /**
     * Upload a payment method icon image (Admin only).
     */
    public function uploadPaymentIcon(Request $request)
    {
        try {
            $currentUser = $request->user();
            if (! $currentUser->hasAdminAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins can upload payment icons.',
                ], 403);
            }

            $request->validate([
                'icon' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,gif,svg,webp,ico', 'max:2048'],
            ]);

            $file = $request->file('icon');
            $path = $file->store('payment-icons', 'public');

            if (! $path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to store the uploaded file.',
                ], 500);
            }

            $url = '/storage/' . $path;

            return response()->json([
                'success' => true,
                'message' => 'Payment icon uploaded successfully.',
                'data' => ['url' => $url],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload payment icon',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred.',
            ], 500);
        }
    }

    private function buildFooterData(): array
    {
        $content = FooterContent::first();
        if (! $content) {
            return $this->defaultFooterStructure();
        }
        $companyLinks = FooterLink::ofType(FooterLink::TYPE_COMPANY)->ordered()->get();
        $supportLinks = FooterLink::ofType(FooterLink::TYPE_SUPPORT)->ordered()->get();
        $policyLinks = FooterLink::ofType(FooterLink::TYPE_POLICY)->ordered()->get();
        $policyCenterLinks = FooterLink::ofType(FooterLink::TYPE_POLICY_CENTER)->ordered()->get();
        $brandsLinks = FooterLink::ofType(FooterLink::TYPE_OUR_BRANDS)->ordered()->get();
        $paymentMethods = FooterPaymentMethod::ordered()->get();

        return [
            'contact_info' => [
                'section_heading' => $content->contact_section_heading,
                'phone' => $content->phone,
                'fax' => $content->fax,
                'email' => $content->email,
                'corporate_address' => $content->corporate_address ?? $content->company_address,
                'company_address' => $content->company_address ?? $content->corporate_address,
                'hours_mon_fri' => $content->hours_mon_fri,
                'hours_sat' => $content->hours_sat,
                'hours_sun_holidays' => $content->hours_sun_holidays,
                'chat_title' => $content->chat_title,
                'chat_subtitle' => $content->chat_subtitle,
            ],
            'company' => [
                'section_heading' => $content->company_section_heading,
                'links' => $companyLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
            ],
            'support' => [
                'section_heading' => $content->support_section_heading,
                'links' => $supportLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
            ],
            'policy' => [
                'section_heading' => $content->policy_section_heading,
                'links' => $policyLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
            ],
            'policy_center' => [
                'section_heading' => $content->policy_center_section_heading,
                'links' => $policyCenterLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
            ],
            'our_brands' => [
                'section_heading' => $content->our_brands_section_heading,
                'links' => $brandsLinks->map(fn ($link) => ['id' => $link->id, 'text' => $link->label, 'path' => $link->path, 'sort_order' => $link->sort_order]),
            ],
            'social_links' => [
                'section_heading' => $content->social_links_section_heading,
                'facebook_url' => $content->facebook_url,
                'twitter_url' => $content->twitter_url,
                'instagram_url' => $content->instagram_url,
                'linkedin_url' => $content->linkedin_url,
                'tiktok_url' => $content->tiktok_url,
                'youtube_url' => $content->youtube_url,
            ],
            'payment_methods' => [
                'section_heading' => $content->payment_methods_section_heading,
                'items' => $paymentMethods->map(fn ($pm) => [
                    'id' => $pm->id,
                    'name' => $pm->name,
                    'image_url' => $pm->image_url,
                    'is_enabled' => $pm->is_enabled,
                    'sort_order' => $pm->sort_order,
                ]),
            ],
            'copyright_text' => $content->copyright_text,
        ];
    }

    private function defaultFooterStructure(): array
    {
        return [
            'contact_info' => [
                'section_heading' => 'CONTACT INFO',
                'phone' => null,
                'fax' => null,
                'email' => null,
                'corporate_address' => null,
                'company_address' => null,
                'hours_mon_fri' => null,
                'hours_sat' => null,
                'hours_sun_holidays' => null,
                'chat_title' => null,
                'chat_subtitle' => null,
            ],
            'company' => ['section_heading' => 'COMPANY', 'links' => []],
            'support' => ['section_heading' => 'SUPPORT', 'links' => []],
            'policy' => ['section_heading' => 'POLICY', 'links' => []],
            'policy_center' => ['section_heading' => 'POLICY CENTER', 'links' => []],
            'our_brands' => ['section_heading' => 'OUR BRANDS', 'links' => []],
            'social_links' => [
                'section_heading' => 'SOCIAL LINKS',
                'facebook_url' => null,
                'twitter_url' => null,
                'instagram_url' => null,
                'linkedin_url' => null,
                'tiktok_url' => null,
                'youtube_url' => null,
            ],
            'payment_methods' => ['section_heading' => 'PAYMENT METHODS', 'items' => []],
            'copyright_text' => null,
        ];
    }
}
