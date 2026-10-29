<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNote;
use App\Models\AdminNoteCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NoteController extends Controller
{
    /**
     * Default categories matching Image 1
     */
    public static function getDefaultCategories()
    {
        return [
            ['name' => 'Production', 'color' => '#3B82F6', 'icon' => 'production', 'order_num' => 1],
            ['name' => 'Suppliers', 'color' => '#06B6D4', 'icon' => 'suppliers', 'order_num' => 2],
            ['name' => 'Customers', 'color' => '#8B5CF6', 'icon' => 'customers', 'order_num' => 3],
            ['name' => 'Designs', 'color' => '#F97316', 'icon' => 'designs', 'order_num' => 4],
            ['name' => 'Pricing', 'color' => '#F59E0B', 'icon' => 'pricing', 'order_num' => 5],
            ['name' => 'Marketing', 'color' => '#10B981', 'icon' => 'marketing', 'order_num' => 6],
            ['name' => 'Equipment', 'color' => '#EC4899', 'icon' => 'equipment', 'order_num' => 7],
            ['name' => 'Events', 'color' => '#2563EB', 'icon' => 'events', 'order_num' => 8],
            ['name' => 'HR & Team', 'color' => '#7C3AED', 'icon' => 'hr', 'order_num' => 9],
            ['name' => 'General', 'color' => '#059669', 'icon' => 'general', 'order_num' => 10],
            ['name' => 'Operations', 'color' => '#6366F1', 'icon' => 'operations', 'order_num' => 11],
        ];
    }

    /**
     * Default seed notes exactly matching Image 1 and Image 3
     */
    public static function getDefaultNotes()
    {
        return [
            // 1. Production Guidelines (Card 1 in Grid & Item 1 in Pinned Notes)
            [
                'note_id' => 'NOT-1001',
                'title' => 'Production Guidelines',
                'category' => 'Production',
                'category_color' => '#EF4444',
                'category_icon' => 'production',
                'note_date' => 'Sep 28, 2026 10:24 AM',
                'is_pinned' => true,
                'thumbnail_image' => '/assets/images/notes/pin_embroidery.png',
                'content' => 'Key guidelines to ensure consistent quality and correct embroidery output for all orders.',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Use correct stabilizer per fabric', 'completed' => true],
                    ['id' => 2, 'text' => 'Verify thread color codes', 'completed' => true],
                    ['id' => 3, 'text' => 'Check placement before running', 'completed' => true],
                    ['id' => 4, 'text' => 'Confirm design size', 'completed' => false],
                    ['id' => 5, 'text' => 'Final quality inspection', 'completed' => false],
                ],
                'order_num' => 1,
            ],

            // 2. Customer Preferences (Card 2 in Grid)
            [
                'note_id' => 'NOT-1002',
                'title' => 'Customer Preferences',
                'category' => 'Customers',
                'category_color' => '#0284C7',
                'category_icon' => 'customers',
                'note_date' => 'Sep 25, 2026 02:20 PM',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'content' => null,
                'bullet_items' => [
                    'Prefers matte finish',
                    'Use YKK zippers',
                    'No tags on neck',
                    'Logo size 3.5" wide',
                    'Avoid thin lines',
                    'Test on black and navy',
                ],
                'gallery_images' => null,
                'order_num' => 2,
            ],

            // 3. Pricing Reference (Card 3 in Grid & Item 3 in Pinned Notes)
            [
                'note_id' => 'NOT-1003',
                'title' => 'Pricing Reference',
                'category' => 'Pricing',
                'category_color' => '#F59E0B',
                'category_icon' => 'pricing',
                'note_date' => 'Sep 22, 2026 11:05 AM',
                'is_pinned' => true,
                'thumbnail_image' => '/assets/images/notes/pin_pricing.png',
                'content' => null,
                'pricing_items' => [
                    ['id' => 1, 'item' => 'T-Shirt', 'left_chest' => '$8', 'full_front' => '$15', 'full_back' => '$18'],
                    ['id' => 2, 'item' => 'Polo', 'left_chest' => '$10', 'full_front' => '$18', 'full_back' => '$22'],
                    ['id' => 3, 'item' => 'Hoodie', 'left_chest' => '$12', 'full_front' => '$20', 'full_back' => '$25'],
                    ['id' => 4, 'item' => 'Jacket', 'left_chest' => '$14', 'full_front' => '$22', 'full_back' => '$28'],
                    ['id' => 5, 'item' => 'Hat', 'left_chest' => '$8', 'full_front' => '$15', 'full_back' => '$18'],
                ],
                'order_num' => 3,
            ],

            // 4. Supplier Information (Card 4 in Grid)
            [
                'note_id' => 'NOT-1004',
                'title' => 'Supplier Information',
                'category' => 'Suppliers',
                'category_color' => '#10B981',
                'category_icon' => 'suppliers',
                'note_date' => 'Oct 3, 2026 04:15 PM',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'content' => 'Main suppliers for uniforms, hats and accessories. Minimum order and turnaround time.',
                'contact_items' => [
                    ['id' => 1, 'type' => 'phone', 'label' => 'Alpha Uniforms', 'value' => '1-800-225-5345'],
                    ['id' => 2, 'type' => 'phone', 'label' => 'SanMar', 'value' => '1-800-346-3369'],
                    ['id' => 3, 'type' => 'phone', 'label' => 'S&S Activewear', 'value' => '1-800-523-2155'],
                    ['id' => 4, 'type' => 'order', 'label' => 'Minimum Order', 'value' => '50 pcs'],
                    ['id' => 5, 'type' => 'turnaround', 'label' => 'Turnaround Time', 'value' => '7-10 days'],
                ],
                'order_num' => 4,
            ],

            // 5. Design Notes (Card 5 in Grid)
            [
                'note_id' => 'NOT-1005',
                'title' => 'Design Notes',
                'category' => 'Designs',
                'category_color' => '#8B5CF6',
                'category_icon' => 'designs',
                'note_date' => 'Sep 18, 2026 09:30 AM',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'content' => null,
                'bullet_items' => [
                    'Use PMS 186C for red',
                    'Keep spacing consistent',
                    'Avoid thin lines',
                    'Test on black and navy',
                    'Convert all text to outlines',
                ],
                'gallery_images' => null,
                'order_num' => 5,
            ],

            // 6. Marketing Ideas (Card 6 in Grid)
            [
                'note_id' => 'NOT-1006',
                'title' => 'Marketing Ideas',
                'category' => 'Marketing',
                'category_color' => '#0284C7',
                'category_icon' => 'marketing',
                'note_date' => 'Oct 1, 2026 11:32 AM',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'content' => null,
                'bullet_items' => [
                    'Holiday promotion',
                    'Create video content',
                    'Instagram reel ideas',
                    'Customer testimonials',
                    'Update homepage banner',
                ],
                'gallery_images' => null,
                'order_num' => 6,
            ],

            // 7. Equipment Maintenance (Card 7 in Grid)
            [
                'note_id' => 'NOT-1007',
                'title' => 'Equipment Maintenance',
                'category' => 'Equipment',
                'category_color' => '#EC4899',
                'category_icon' => 'equipment',
                'note_date' => 'Sep 10, 2026 01:45 PM',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'content' => null,
                'bullet_items' => [
                    'Clean cap hoop weekly',
                    'Replace needles monthly',
                    'Check tension settings',
                    'Service machine (Oct 2026)',
                ],
                'order_num' => 7,
            ],

            // 8. Event Details (Card 8 in Grid)
            [
                'note_id' => 'NOT-1008',
                'title' => 'Event Details',
                'category' => 'Events',
                'category_color' => '#3B82F6',
                'category_icon' => 'events',
                'note_date' => 'Sep 5, 2026 10:10 AM',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'content' => null,
                'bullet_items' => [
                    'Company Expo 2026',
                    'Date: Nov 14, 2026',
                    'Location: Atlanta Convention Center',
                    'Items: Polos, Caps, Lanyards',
                ],
                'order_num' => 8,
            ],

            // 9. Important Contacts (Card 9 in Grid)
            [
                'note_id' => 'NOT-1009',
                'title' => 'Important Contacts',
                'category' => 'General',
                'category_color' => '#059669',
                'category_icon' => 'general',
                'note_date' => 'Aug 28, 2026 11:02 AM',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'content' => null,
                'contact_items' => [
                    ['id' => 1, 'type' => 'phone', 'label' => 'DHL', 'value' => '1-800-225-5345'],
                    ['id' => 2, 'type' => 'truck', 'label' => 'UPS', 'value' => '1-800-742-5877'],
                    ['id' => 3, 'type' => 'truck', 'label' => 'FedEx', 'value' => '1-800-463-3339'],
                    ['id' => 4, 'type' => 'location', 'label' => 'Local Courier', 'value' => '404-555-0187'],
                ],
                'order_num' => 9,
            ],

            // 10. Store Setup – Alpharetta Location (Image 3 View Note modal)
            [
                'note_id' => 'NOT-1010',
                'title' => 'Store Setup – Alpharetta Location',
                'category' => 'Operations',
                'category_color' => '#6366F1',
                'category_icon' => 'operations',
                'note_date' => 'Oct 20, 2026',
                'is_pinned' => false,
                'thumbnail_image' => null,
                'banner_image' => null,
                'content' => 'Key tasks and requirements for the new Alpharetta store. Please review the checklist, timeline, and uploaded files. This location will include retail, embroidery, and sign services. Ensure all vendors and permits are finalized before the opening date.',
                'checklist_items' => [
                    ['id' => 1, 'text' => 'Finalize lease agreement', 'completed' => true],
                    ['id' => 2, 'text' => 'Order signage and wall graphics', 'completed' => true],
                    ['id' => 3, 'text' => 'Set up internet and phone system', 'completed' => true],
                    ['id' => 4, 'text' => 'Schedule equipment delivery', 'completed' => false],
                    ['id' => 5, 'text' => 'Confirm opening date with marketing team', 'completed' => false],
                ],
                'timeline_items' => [
                    ['id' => 1, 'task' => 'Lease Finalization', 'start_date' => 'Oct 01, 2026', 'end_date' => 'Oct 10, 2026', 'status' => 'Completed', 'remarks' => 'Signed and filed'],
                    ['id' => 2, 'task' => 'Store Build-Out', 'start_date' => 'Oct 12, 2026', 'end_date' => 'Oct 25, 2026', 'status' => 'In Progress', 'remarks' => 'On schedule'],
                    ['id' => 3, 'task' => 'Equipment Setup', 'start_date' => 'Oct 20, 2026', 'end_date' => 'Nov 05, 2026', 'status' => 'Pending', 'remarks' => 'Awaiting delivery'],
                    ['id' => 4, 'task' => 'Grand Opening', 'start_date' => 'Nov 10, 2026', 'end_date' => 'Nov 10, 2026', 'status' => 'Pending', 'remarks' => 'Marketing confirmation'],
                ],
                'additional_notes' => [
                    'Coordinate with IT for network setup.',
                    'Confirm uniform samples for staff.',
                    'Ensure all permits are submitted before opening.',
                ],
                'attachments' => [
                    ['id' => 1, 'name' => 'store-front.jpg', 'size' => '2.4 MB', 'type' => 'image', 'url' => '', 'thumbnail' => ''],
                    ['id' => 2, 'name' => 'floor-plan.pdf', 'size' => '1.1 MB', 'type' => 'pdf', 'url' => '', 'thumbnail' => ''],
                    ['id' => 3, 'name' => 'layout.png', 'size' => '3.2 MB', 'type' => 'image', 'url' => '', 'thumbnail' => ''],
                    ['id' => 4, 'name' => 'equipment.xlsx', 'size' => '856 KB', 'type' => 'excel', 'url' => '', 'thumbnail' => ''],
                ],
                'order_num' => 10,
            ],

            // 11. Pinned Note: Supplier Contacts (Item 2 in Pinned Notes Card)
            [
                'note_id' => 'NOT-1011',
                'title' => 'Supplier Contacts',
                'category' => 'Suppliers',
                'category_color' => '#06B6D4',
                'category_icon' => 'suppliers',
                'note_date' => 'Sep 25, 2026',
                'is_pinned' => true,
                'thumbnail_image' => '/assets/images/notes/pin_sewing.png',
                'content' => 'Comprehensive directory of blanks, threads, needles, and packaging vendors.',
                'bullet_items' => [
                    'Madeira Thread: Rep Dave 800-555-0199',
                    'SanMar Account: #488921-AZ',
                    'S&S Activewear Rep: Sarah Wilson',
                ],
                'order_num' => 11,
            ],

            // 12. Pinned Note: Design Templates (Item 4 in Pinned Notes Card)
            [
                'note_id' => 'NOT-1012',
                'title' => 'Design Templates',
                'category' => 'Designs',
                'category_color' => '#F97316',
                'category_icon' => 'designs',
                'note_date' => 'Sep 18, 2026',
                'is_pinned' => true,
                'thumbnail_image' => '/assets/images/notes/pin_m_logo.png',
                'content' => 'Standard DST files and vector templates for left chest and cap placements.',
                'bullet_items' => [
                    'Standard Left Chest: 3.5in x 2.2in',
                    'Cap Crown Center: 2.25in height max',
                    'Jacket Back: 10.5in x 10.5in max hoop',
                ],
                'order_num' => 12,
            ],

            // 13. Additional notes to populate realistic category counts (24 total)
            [
                'note_id' => 'NOT-1013',
                'title' => 'Hooping & Stabilizer Guide',
                'category' => 'Production',
                'category_color' => '#EF4444',
                'category_icon' => 'production',
                'note_date' => 'Sep 27, 2026',
                'is_pinned' => false,
                'content' => 'Backing rules: Tearaway for firm wovens, Cutaway 2.5oz for pique knit polos and performance wear.',
                'order_num' => 13,
            ],
            [
                'note_id' => 'NOT-1014',
                'title' => 'Tension Calibration Chart',
                'category' => 'Production',
                'category_color' => '#EF4444',
                'category_icon' => 'production',
                'note_date' => 'Sep 26, 2026',
                'is_pinned' => false,
                'content' => 'Bobbin pull tension should read 20-25g on gauge. Top thread tension at 110-130g for polyester 40wt.',
                'order_num' => 14,
            ],
            [
                'note_id' => 'NOT-1015',
                'title' => 'Needle Specifications',
                'category' => 'Production',
                'category_color' => '#EF4444',
                'category_icon' => 'production',
                'note_date' => 'Sep 24, 2026',
                'is_pinned' => false,
                'content' => 'Use 75/11 Sharp for caps and canvas totes; 75/11 Ballpoint for soft knit t-shirts and polos.',
                'order_num' => 15,
            ],
            [
                'note_id' => 'NOT-1016',
                'title' => 'End of Shift Cleanup Checklist',
                'category' => 'Production',
                'category_color' => '#EF4444',
                'category_icon' => 'production',
                'note_date' => 'Sep 20, 2026',
                'is_pinned' => false,
                'content' => 'Blow out rotary hook with compressed air, wipe down needle plates, cover machine heads.',
                'order_num' => 16,
            ],
            [
                'note_id' => 'NOT-1017',
                'title' => 'Freight Terms & Expedited Rates',
                'category' => 'Suppliers',
                'category_color' => '#10B981',
                'category_icon' => 'suppliers',
                'note_date' => 'Sep 21, 2026',
                'is_pinned' => false,
                'content' => 'SanMar free ground shipping threshold is $200. S&S threshold is $250. Orders placed by 4pm ship same-day.',
                'order_num' => 17,
            ],
            [
                'note_id' => 'NOT-1018',
                'title' => 'Thread Color Substitution Matrix',
                'category' => 'Suppliers',
                'category_color' => '#10B981',
                'category_icon' => 'suppliers',
                'note_date' => 'Sep 15, 2026',
                'is_pinned' => false,
                'content' => 'Madeira Polyneon 1801 -> Isacord 0010. Madeira 1682 -> Isacord 1902.',
                'order_num' => 18,
            ],
            [
                'note_id' => 'NOT-1019',
                'title' => 'VIP Account Special Instructions',
                'category' => 'Customers',
                'category_color' => '#0284C7',
                'category_icon' => 'customers',
                'note_date' => 'Sep 14, 2026',
                'is_pinned' => false,
                'content' => 'TechCorp orders always require custom hangtags and individual fold & polybag packaging.',
                'order_num' => 19,
            ],
            [
                'note_id' => 'NOT-1020',
                'title' => 'Corporate Volume Discount Tiers',
                'category' => 'Pricing',
                'category_color' => '#F59E0B',
                'category_icon' => 'pricing',
                'note_date' => 'Sep 12, 2026',
                'is_pinned' => false,
                'content' => '50-99 pcs: 10% off stitch fee. 100-249 pcs: 18% off stitch fee. 250+ pcs: 25% off stitch fee.',
                'order_num' => 20,
            ],
            [
                'note_id' => 'NOT-1021',
                'title' => 'Digitizing Fee Policy',
                'category' => 'Pricing',
                'category_color' => '#F59E0B',
                'category_icon' => 'pricing',
                'note_date' => 'Sep 11, 2026',
                'is_pinned' => false,
                'content' => 'Standard digitizing flat $25 up to 10k stitches. Jacket backs $65 flat. Free digitizing on orders 75+ pcs.',
                'order_num' => 21,
            ],
            [
                'note_id' => 'NOT-1022',
                'title' => 'Tajima 8-Head Spare Parts List',
                'category' => 'Equipment',
                'category_color' => '#EC4899',
                'category_icon' => 'equipment',
                'note_date' => 'Sep 8, 2026',
                'is_pinned' => false,
                'content' => 'Stock in cabinet: 6 rotary hooks, 20 bobbin cases, 100 packs DBxK5 75/11 needles.',
                'order_num' => 22,
            ],
            [
                'note_id' => 'NOT-1023',
                'title' => 'Embroidery Technician Onboarding',
                'category' => 'HR & Team',
                'category_color' => '#7C3AED',
                'category_icon' => 'hr',
                'note_date' => 'Sep 2, 2026',
                'is_pinned' => false,
                'content' => 'Week 1 hooping and machine safety, Week 2 tension adjustment and thread changes, Week 3 solo runs.',
                'order_num' => 23,
            ],
            [
                'note_id' => 'NOT-1024',
                'title' => 'Building Access Codes & Wi-Fi',
                'category' => 'General',
                'category_color' => '#059669',
                'category_icon' => 'general',
                'note_date' => 'Aug 25, 2026',
                'is_pinned' => false,
                'content' => 'Shop alarm code: 4892. Wi-Fi SSID: MecarviStaff_5G. Loading dock bay code: 1104.',
                'order_num' => 24,
            ],
        ];
    }

    /**
     * Seed or sync default categories and notes
     */
    private function ensureDefaultsSeeded()
    {
        if (AdminNoteCategory::count() === 0) {
            foreach (self::getDefaultCategories() as $cat) {
                AdminNoteCategory::create($cat);
            }
        }
    }

    /**
     * Display a listing of notes, pinned notes, and categories with counts
     */
    public function index(Request $request)
    {
        $this->ensureDefaultsSeeded();

        $query = AdminNote::query()->orderBy('updated_at', 'desc')->orderBy('id', 'desc');

        // Filter by category
        if ($request->filled('category') && strtolower($request->input('category')) !== 'all notes' && $request->input('category') !== 'all') {
            $categoryName = trim($request->input('category'));
            $query->where('category', 'ilike', $categoryName);
        }

        // Search query
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%")
                  ->orWhere('category', 'ilike', "%{$search}%");
            });
        }

        $notes = $query->get();

        // Real pinned notes ordered by most recently updated
        $pinnedNotes = AdminNote::where('is_pinned', true)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->take(6)
            ->get();

        // Calculate dynamic counts per category
        $allNotes = AdminNote::all();
        $totalNotesCount = $allNotes->count();

        $categories = AdminNoteCategory::orderBy('order_num', 'asc')->get();
        $categoryCounts = [];
        foreach ($categories as $cat) {
            $count = $allNotes->filter(function ($n) use ($cat) {
                return strcasecmp($n->category, $cat->name) === 0;
            })->count();

            $categoryCounts[] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'color' => $cat->color,
                'icon' => $cat->icon,
                'count' => $count,
            ];
        }

        return response()->json([
            'success' => true,
            'total_count' => $totalNotesCount,
            'data' => $notes,
            'pinned_notes' => $pinnedNotes,
            'categories' => $categoryCounts,
        ]);
    }

    /**
     * Store a newly created note
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'category_color' => 'nullable|string|max:50',
            'category_icon' => 'nullable|string|max:50',
            'note_date' => 'nullable|string',
            'is_pinned' => 'nullable|boolean',
            'content' => 'nullable|string',
            'checklist_items' => 'nullable|array',
            'timeline_items' => 'nullable|array',
            'pricing_items' => 'nullable|array',
            'contact_items' => 'nullable|array',
            'bullet_items' => 'nullable|array',
            'gallery_images' => 'nullable|array',
            'attachments' => 'nullable|array',
            'additional_notes' => 'nullable',
            'banner_image' => 'nullable|string',
            'thumbnail_image' => 'nullable|string',
            'created_by' => 'nullable|string',
        ]);

        $noteId = 'NOT-' . strtoupper(Str::random(6));
        $category = $validated['category'] ?? 'General';

        // Auto color mapping if none provided
        $categoryColors = [
            'production' => '#EF4444',
            'suppliers' => '#10B981',
            'customers' => '#0284C7',
            'designs' => '#8B5CF6',
            'pricing' => '#F59E0B',
            'marketing' => '#0284C7',
            'equipment' => '#EC4899',
            'events' => '#3B82F6',
            'hr & team' => '#7C3AED',
            'general' => '#10B981',
            'operations' => '#6366F1',
        ];
        $catKey = strtolower(trim($category));
        $color = $validated['category_color'] ?? ($categoryColors[$catKey] ?? '#EF4444');

        // Auto-extract gallery_images and thumbnail_image from image attachments
        $galleryImages = $validated['gallery_images'] ?? [];
        if (empty($galleryImages) && !empty($validated['attachments'])) {
            foreach ($validated['attachments'] as $att) {
                $isImg = ($att['type'] ?? '') === 'image' || 
                         preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $att['url'] ?? '') ||
                         preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $att['name'] ?? '');
                $u = $att['thumbnail'] ?? ($att['url'] ?? null);
                if ($isImg && !empty($u) && !in_array($u, $galleryImages)) {
                    $galleryImages[] = $u;
                }
            }
        }
        $thumbnailImage = $validated['thumbnail_image'] ?? ($galleryImages[0] ?? null);
        $bannerImage = $validated['banner_image'] ?? ($galleryImages[0] ?? null);

        $note = AdminNote::create([
            'note_id' => $noteId,
            'title' => $validated['title'],
            'category' => $category,
            'category_color' => $color,
            'category_icon' => $validated['category_icon'] ?? 'file',
            'note_date' => $validated['note_date'] ?? Carbon::now()->format('M d, Y h:i A'),
            'is_pinned' => !empty($validated['is_pinned']),
            'content' => $validated['content'] ?? '',
            'checklist_items' => $validated['checklist_items'] ?? null,
            'timeline_items' => $validated['timeline_items'] ?? null,
            'pricing_items' => $validated['pricing_items'] ?? null,
            'contact_items' => $validated['contact_items'] ?? null,
            'bullet_items' => $validated['bullet_items'] ?? null,
            'gallery_images' => !empty($galleryImages) ? $galleryImages : null,
            'attachments' => $validated['attachments'] ?? null,
            'additional_notes' => $validated['additional_notes'] ?? null,
            'banner_image' => $bannerImage,
            'thumbnail_image' => $thumbnailImage,
            'created_by' => $validated['created_by'] ?? 'Admin',
            'order_num' => 0,
        ]);

        // Auto register category if not exists
        if (!AdminNoteCategory::where('name', $category)->exists()) {
            AdminNoteCategory::create([
                'name' => $category,
                'color' => $color,
                'icon' => 'folder',
                'order_num' => AdminNoteCategory::count() + 1,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Note created successfully',
            'data' => $note,
        ], 201);
    }

    /**
     * Find a note by integer ID or string note_id (Postgres-safe)
     */
    private function findNote($id)
    {
        if (is_numeric($id)) {
            return AdminNote::where('id', (int)$id)->orWhere('note_id', (string)$id)->first();
        }
        return AdminNote::where('note_id', (string)$id)->first();
    }

    /**
     * Display a specific note
     */
    public function show($id)
    {
        $note = $this->findNote($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $note,
        ]);
    }

    /**
     * Update an existing note
     */
    public function update(Request $request, $id)
    {
        $note = $this->findNote($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|max:100',
            'category_color' => 'nullable|string|max:50',
            'category_icon' => 'nullable|string|max:50',
            'note_date' => 'nullable|string',
            'is_pinned' => 'nullable|boolean',
            'content' => 'nullable|string',
            'checklist_items' => 'nullable|array',
            'timeline_items' => 'nullable|array',
            'pricing_items' => 'nullable|array',
            'contact_items' => 'nullable|array',
            'bullet_items' => 'nullable|array',
            'gallery_images' => 'nullable|array',
            'attachments' => 'nullable|array',
            'additional_notes' => 'nullable',
            'banner_image' => 'nullable|string',
            'thumbnail_image' => 'nullable|string',
        ]);

        // Auto-extract gallery_images and thumbnail_image from image attachments if not explicitly provided
        if (empty($validated['gallery_images']) && !empty($validated['attachments'])) {
            $galleryImages = [];
            foreach ($validated['attachments'] as $att) {
                $isImg = ($att['type'] ?? '') === 'image' || 
                         preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $att['url'] ?? '') ||
                         preg_match('/\.(png|jpe?g|webp|gif|svg)$/i', $att['name'] ?? '');
                $u = $att['thumbnail'] ?? ($att['url'] ?? null);
                if ($isImg && !empty($u) && !in_array($u, $galleryImages)) {
                    $galleryImages[] = $u;
                }
            }
            if (!empty($galleryImages)) {
                $validated['gallery_images'] = $galleryImages;
                if (empty($validated['thumbnail_image'])) {
                    $validated['thumbnail_image'] = $galleryImages[0];
                }
                if (empty($validated['banner_image'])) {
                    $validated['banner_image'] = $galleryImages[0];
                }
            }
        }

        $note->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Note updated successfully',
            'data' => $note,
        ]);
    }

    /**
     * Delete a note
     */
    public function destroy($id)
    {
        $note = $this->findNote($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 404);
        }

        $note->delete();

        return response()->json([
            'success' => true,
            'message' => 'Note deleted successfully',
        ]);
    }

    /**
     * Toggle pinned status
     */
    public function togglePin($id)
    {
        $note = $this->findNote($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 404);
        }

        $note->is_pinned = !$note->is_pinned;
        $note->save();

        return response()->json([
            'success' => true,
            'message' => $note->is_pinned ? 'Note pinned' : 'Note unpinned',
            'data' => $note,
        ]);
    }

    /**
     * Toggle checklist item completed status
     */
    public function toggleChecklist(Request $request, $id, $itemIndex)
    {
        $note = $this->findNote($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found',
            ], 404);
        }

        $checklist = $note->checklist_items ?? [];
        $idx = (int)$itemIndex;

        if (isset($checklist[$idx])) {
            $checklist[$idx]['completed'] = !$checklist[$idx]['completed'];
            $note->checklist_items = $checklist;
            $note->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Checklist item updated',
            'data' => $note,
        ]);
    }

    /**
     * Upload an attachment
     */
    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480', // 20MB
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $sizeBytes = $file->getSize();

        // Format size
        if ($sizeBytes >= 1048576) {
            $sizeFormatted = round($sizeBytes / 1048576, 1) . ' MB';
        } else {
            $sizeFormatted = round($sizeBytes / 1024) . ' KB';
        }

        // Determine type
        $type = 'file';
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
            $type = 'image';
        } elseif ($extension === 'pdf') {
            $type = 'pdf';
        } elseif (in_array($extension, ['xls', 'xlsx', 'csv'])) {
            $type = 'excel';
        } elseif (in_array($extension, ['doc', 'docx'])) {
            $type = 'word';
        }

        $filename = time() . '_' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $extension;
        $path = $file->storeAs('notes/attachments', $filename, 'public');
        $url = Storage::url($path);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => time(),
                'name' => $originalName,
                'size' => $sizeFormatted,
                'type' => $type,
                'url' => $url,
                'thumbnail' => $type === 'image' ? $url : ($type === 'pdf' ? '/assets/images/todo/pdf_icon.png' : '/assets/images/todo/excel_icon.png'),
            ],
        ]);
    }

    /**
     * Store new category
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:admin_note_categories,name',
            'color' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
        ]);

        $category = AdminNoteCategory::create([
            'name' => trim($validated['name']),
            'color' => $validated['color'] ?? '#3B82F6',
            'icon' => $validated['icon'] ?? 'folder',
            'order_num' => AdminNoteCategory::count() + 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created',
            'data' => $category,
        ]);
    }

    /**
     * Update existing category (rename, change color, update existing notes)
     */
    public function updateCategory(Request $request, $id)
    {
        $category = AdminNoteCategory::find($id);
        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:admin_note_categories,name,' . $id,
            'color' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
        ]);

        $oldName = $category->name;
        $newName = trim($validated['name']);

        $category->name = $newName;
        if (!empty($validated['color'])) {
            $category->color = $validated['color'];
        }
        if (!empty($validated['icon'])) {
            $category->icon = $validated['icon'];
        }
        $category->save();

        // Update all existing notes using this category
        if (strcasecmp($oldName, $newName) !== 0) {
            AdminNote::where('category', 'ilike', $oldName)->update([
                'category' => $newName,
                'category_color' => $category->color,
            ]);
        } elseif (!empty($validated['color'])) {
            AdminNote::where('category', 'ilike', $newName)->update([
                'category_color' => $category->color,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category,
        ]);
    }

    /**
     * Delete a category
     */
    public function destroyCategory($id)
    {
        $category = AdminNoteCategory::find($id);
        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }

        $name = $category->name;
        $category->delete();

        // Move remaining notes in this category to General so they aren't lost
        AdminNote::where('category', 'ilike', $name)->update([
            'category' => 'General',
            'category_color' => '#059669',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Category '{$name}' deleted",
        ]);
    }

    /**
     * Reset notes and categories to default
     */
    public function resetDefaults()
    {
        AdminNote::truncate();
        AdminNoteCategory::truncate();

        foreach (self::getDefaultCategories() as $cat) {
            AdminNoteCategory::create($cat);
        }

        foreach (self::getDefaultNotes() as $noteData) {
            AdminNote::create($noteData);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notes reset to defaults successfully',
        ]);
    }
}
