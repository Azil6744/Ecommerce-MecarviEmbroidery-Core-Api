<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\KnowledgeBaseSubcategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Starter Knowledge Base content matching the approved website design.
 *
 * Safe to run again: categories, subcategories and articles are matched by name/slug and updated in place.
 * Nothing that is not listed here is deleted. Review and edit the copy in the admin panel after seeding.
 *
 *   php artisan db:seed --class=KnowledgeBaseDesignSeeder
 */
class KnowledgeBaseDesignSeeder extends Seeder
{
    /** Categories in sidebar/hub order. The first six are the ones shown on the hub page. */
    private const CATEGORIES = [
        ['Artwork Guidelines', 'pink'],
        ['Digitizing Services', 'purple'],
        ['Embroidery Services', 'pink'],
        ['Thread & Materials', 'orange'],
        ['Garment & Placement', 'teal'],
        ['File Formats', 'blue'],
        ['Orders & Production', 'purple'],
        ['Shipping & Delivery', 'purple'],
        ['Care & Maintenance', 'pink'],
        ['Returns & Support', 'green'],
        ['Account & Billing', 'orange'],
        ['Business Orders', 'purple'],
    ];

    /** Subcategories shown under Artwork Guidelines in the design. */
    private const SUBCATEGORIES = [
        'Artwork Guidelines' => ['File Preparation', 'Image Requirements', 'Accepted File Formats', 'Design Limitations', 'Artwork Approval'],
    ];

    public function run(): void
    {
        $categoryIds = [];
        foreach (self::CATEGORIES as $i => [$name, $tone]) {
            $category = KnowledgeBaseCategory::updateOrCreate(
                ['name' => $name],
                [
                    'slug' => Str::slug($name),
                    'status' => 'active',
                    'sort_order' => $i + 1,
                    'icon' => 'folder',
                    'tone' => $tone,
                    'meta_description' => $this->categoryBlurb($name),
                ]
            );
            $categoryIds[$name] = $category->id;
        }

        $subIds = [];
        foreach (self::SUBCATEGORIES as $parent => $names) {
            foreach ($names as $i => $subName) {
                $sub = KnowledgeBaseSubcategory::updateOrCreate(
                    ['category_id' => $categoryIds[$parent], 'name' => $subName],
                    [
                        'slug' => Str::slug($parent . ' ' . $subName),
                        'display_order' => $i + 1,
                        'status' => 'active',
                    ]
                );
                $subIds[$subName] = $sub->id;
            }
        }

        $articles = $this->articles();
        $bySlug = [];
        foreach ($articles as $i => $a) {
            // Views decide the featured article and the popular list; keep them in the design's order.
            $a['views'] = self::VIEWS[$a['slug']] ?? $a['views'];
            $article = KnowledgeBaseArticle::updateOrCreate(
                ['slug' => $a['slug']],
                [
                    'title' => $a['title'],
                    'summary' => $a['summary'],
                    'category_id' => $categoryIds[$a['category']],
                    'subcategory_id' => isset($a['subcategory']) ? $subIds[$a['subcategory']] : null,
                    'tag' => $a['category'],
                    'status' => 'published',
                    'icon' => 'doc',
                    'tone' => $a['tone'],
                    'views' => $a['views'],
                    'helpful_count' => $a['helpful'] ?? 0,
                    'content_blocks' => $a['blocks'],
                    'published_at' => $this->publishedAt($a['slug']),
                ]
            );
            $bySlug[$a['slug']] = $article;
        }

        // Related articles for the featured article
        $bySlug['what-is-embroidery-digitizing']->relatedArticles()->sync([
            $bySlug['common-digitizing-mistakes-to-avoid']->id,
            $bySlug['thread-color-matching-tips']->id,
            $bySlug['accepted-file-formats-for-artwork']->id ?? $bySlug['understanding-dst-file-format']->id,
            $bySlug['embroidery-density-what-you-need-to-know']->id,
        ]);

        $this->command?->info('Knowledge base design content seeded: ' . count(self::CATEGORIES) . ' categories, ' . count($articles) . ' articles.');
    }

    /** Views for each seeded article. Featured = highest, popular = the next four in design order. */
    private const VIEWS = [
        'what-is-embroidery-digitizing' => 4200,
        'how-to-choose-the-right-stitch-type' => 3100,
        'thread-types-and-their-uses' => 2400,
        'understanding-dst-file-format' => 2200,
        'embroidery-on-different-fabrics' => 1800,
        'how-to-prepare-vector-artwork-for-digitizing' => 1500,
        'common-digitizing-mistakes-to-avoid' => 1200,
        'embroidery-density-what-you-need-to-know' => 1000,
        'thread-color-matching-tips' => 900,
        'best-practices-for-text-embroidery' => 800,
        'hoop-sizes-and-placement-guide' => 700,
        'choosing-the-right-stabilizer-for-your-fabric' => 600,
        'applique-vs-embroidery-whats-the-difference' => 500,
        'how-to-read-a-digitizing-worksheet' => 450,
        'how-to-reduce-puckering-in-embroidery' => 400,
        'accepted-file-formats-for-artwork' => 350,
    ];

    /** Helpful Resources order in the design: left column first, then right column, alternating. */
    private const RESOURCE_ORDER = [
        'how-to-prepare-vector-artwork-for-digitizing',
        'best-practices-for-text-embroidery',
        'common-digitizing-mistakes-to-avoid',
        'thread-color-matching-tips',
        'embroidery-density-what-you-need-to-know',
        'hoop-sizes-and-placement-guide',
        'choosing-the-right-stabilizer-for-your-fabric',
        'applique-vs-embroidery-whats-the-difference',
        'how-to-read-a-digitizing-worksheet',
        'how-to-reduce-puckering-in-embroidery',
    ];

    private function publishedAt(string $slug): Carbon
    {
        $position = array_search($slug, self::RESOURCE_ORDER, true);
        return $position !== false
            ? Carbon::now()->subHours($position + 1)
            : Carbon::now()->subDays(30 + (crc32($slug) % 20));
    }

    private function categoryBlurb(string $name): string
    {
        return match ($name) {
            'Artwork Guidelines' => 'Learn how to prepare and submit artwork for the best embroidery results.',
            'Digitizing Services' => 'Everything about our digitizing process, file types and best practices.',
            'Embroidery Services' => 'Learn about our embroidery techniques and processes.',
            'Thread & Materials' => 'Thread types, materials and color matching information.',
            'Garment & Placement' => 'Placement tips and garment recommendations.',
            'File Formats' => 'Understand embroidery file formats, compatibility and requirements.',
            default => "Helpful guides about {$name}.",
        };
    }

    /**
     * Articles shown in the design. Each block is in the same shape the article editor saves.
     */
    private function articles(): array
    {
        $p = fn (string $text) => ['type' => 'paragraph', 'text' => $text];

        return [
            [
                'slug' => 'how-to-prepare-vector-artwork-for-digitizing',
                'title' => 'How to Prepare Vector Artwork for Digitizing',
                'summary' => 'Learn the best file types and requirements for clean and accurate digitizing results.',
                'category' => 'Artwork Guidelines',
                'subcategory' => 'File Preparation',
                'tone' => 'pink',
                'views' => 4200,
                'helpful' => 128,
                'blocks' => [
                    $p('Learn the best file types and requirements for clean and accurate digitizing results. Following these guidelines helps ensure high-quality embroidery.'),
                    ['type' => 'step', 'title' => 'Use Vector Files', 'text' => 'Vector files provide the best results for embroidery digitizing. They allow clean lines, scalable designs and accurate stitch conversion.'],
                    ['type' => 'step', 'title' => 'Keep the Design Clean', 'text' => 'Use clean, smooth lines and avoid unnecessary details, shadows or complex effects. Simpler artwork digitizes better and stitches more accurately.'],
                    ['type' => 'step', 'title' => 'Convert Text to Outlines', 'text' => 'All text should be converted to outlines to prevent font issues. This ensures the text stitches correctly as intended.'],
                    ['type' => 'step', 'title' => 'Check Design Size', 'text' => 'Provide the artwork at the actual size or clearly indicate the intended size. This helps our team optimize the stitch count and maintain detail.'],
                    ['type' => 'step', 'title' => 'Avoid Low-Resolution Images', 'text' => 'Do not use low-resolution images such as screenshots or small web images. They can result in poor quality digitizing and unclear stitching.'],
                ],
            ],
            [
                'slug' => 'what-is-embroidery-digitizing',
                'title' => 'What is Embroidery Digitizing?',
                'summary' => 'A complete guide to embroidery digitizing and how it works.',
                'category' => 'Digitizing Services',
                'tone' => 'purple',
                'views' => 4200,
                'helpful' => 210,
                'blocks' => [
                    $p('Digitizing turns your artwork into a set of stitch instructions that an embroidery machine can follow. The digitizer chooses stitch types, directions and densities so the design sews cleanly on the chosen fabric.'),
                    ['type' => 'heading', 'text' => 'What the digitizer decides', 'level' => 2],
                    ['type' => 'bullet_list', 'items' => ['Stitch type for each area (fill, satin or running stitch)', 'Stitch direction and density', 'Pull compensation and underlay', 'Order in which the design is sewn']],
                ],
            ],
            [
                'slug' => 'how-to-choose-the-right-stitch-type',
                'title' => 'How to Choose the Right Stitch Type',
                'summary' => 'Explore different stitch types and their best applications.',
                'category' => 'Embroidery Services',
                'tone' => 'blue',
                'views' => 3100,
                'helpful' => 96,
                'blocks' => [$p('Satin stitches suit lettering and outlines, fill stitches cover larger shapes, and running stitches work well for fine detail. Your digitizer can recommend the best mix for your design.')],
            ],
            [
                'slug' => 'thread-types-and-their-uses',
                'title' => 'Thread Types and Their Uses',
                'summary' => 'Learn about various thread types and when to use them.',
                'category' => 'Thread & Materials',
                'tone' => 'orange',
                'views' => 2400,
                'helpful' => 74,
                'blocks' => [$p('Polyester thread is durable and colourfast, rayon has a soft shine, and metallic thread adds sparkle. Choose the thread that matches the wash and wear your garment will see.')],
            ],
            [
                'slug' => 'understanding-dst-file-format',
                'title' => 'Understanding DST File Format',
                'summary' => 'Everything you need to know about DST and other formats.',
                'category' => 'File Formats',
                'tone' => 'blue',
                'views' => 2200,
                'helpful' => 65,
                'blocks' => [$p('DST is the most common stitch file format. It stores stitch positions only, so colours are assigned when the design is loaded on the machine. Ask us for PES or EXP if your machine needs them.')],
            ],
            [
                'slug' => 'embroidery-on-different-fabrics',
                'title' => 'Embroidery on Different Fabrics',
                'summary' => 'Tips for getting the best results on different fabric types.',
                'category' => 'Garment & Placement',
                'tone' => 'teal',
                'views' => 1800,
                'helpful' => 52,
                'blocks' => [$p('Stretchy knits need a stabilizer and a lighter stitch density, while woven fabrics such as canvas hold dense designs well. We adjust the stitch plan to the fabric you choose.')],
            ],
            [
                'slug' => 'common-digitizing-mistakes-to-avoid',
                'title' => 'Common Digitizing Mistakes to Avoid',
                'summary' => 'Learn how to fix the most common issues before submitting your artwork.',
                'category' => 'Digitizing Services',
                'tone' => 'purple',
                'views' => 3100,
                'helpful' => 88,
                'blocks' => [$p('Very small text, thin lines and overlapping colours are the most common problems. Keep details larger than the minimum size we list and separate colours clearly.')],
            ],
            [
                'slug' => 'embroidery-density-what-you-need-to-know',
                'title' => 'Embroidery Density: What You Need to Know',
                'summary' => 'Understand how stitch density affects the look and feel of your design.',
                'category' => 'Digitizing Services',
                'tone' => 'purple',
                'views' => 2800,
                'helpful' => 70,
                'blocks' => [$p('Density is the number of stitches packed into an area. Too dense and the fabric puckers; too light and the design looks thin. The right density depends on the fabric and the design.')],
            ],
            [
                'slug' => 'thread-color-matching-tips',
                'title' => 'Thread Color Matching Tips',
                'summary' => 'Tips for selecting and matching thread colors for the best results.',
                'category' => 'Thread & Materials',
                'tone' => 'orange',
                'views' => 2400,
                'helpful' => 61,
                'blocks' => [$p('Send us a Pantone reference or a colour sample when you can. We match thread colours from our chart, so the stitched colours are as close as possible to your brand colours.')],
            ],
            [
                'slug' => 'best-practices-for-text-embroidery',
                'title' => 'Best Practices for Text Embroidery',
                'summary' => 'Tips to get clear and professional text embroidery.',
                'category' => 'Embroidery Services',
                'tone' => 'blue',
                'views' => 2100,
                'helpful' => 58,
                'blocks' => [$p('Use bold, simple fonts and a minimum letter height. Script fonts with thin strokes are hard to read when stitched, so choose a font designed for embroidery where possible.')],
            ],
            [
                'slug' => 'hoop-sizes-and-placement-guide',
                'title' => 'Hoop Sizes and Placement Guide',
                'summary' => 'Find the right hoop size and placement for your garment.',
                'category' => 'Garment & Placement',
                'tone' => 'teal',
                'views' => 1500,
                'helpful' => 44,
                'blocks' => [$p('The design must fit inside the hoop with room to spare. We recommend a hoop about 1 inch larger than the design on each side for stable stitching.')],
            ],
            [
                'slug' => 'choosing-the-right-stabilizer-for-your-fabric',
                'title' => 'Choosing the Right Stabilizer for Your Fabric',
                'summary' => 'Find out which stabilizer works best for your embroidery project.',
                'category' => 'Embroidery Services',
                'tone' => 'blue',
                'views' => 2600,
                'helpful' => 67,
                'blocks' => [$p('Stabilizer keeps the fabric from shifting while you sew. Tear-away works for woven fabrics, cut-away for knits and stretchy materials.')],
            ],
            [
                'slug' => 'applique-vs-embroidery-whats-the-difference',
                'title' => "Appliqué vs. Embroidery: What's the Difference?",
                'summary' => 'Compare the two techniques and choose the right one for your design.',
                'category' => 'Embroidery Services',
                'tone' => 'blue',
                'views' => 1900,
                'helpful' => 49,
                'blocks' => [$p('Embroidery stitches the design directly onto the garment. Appliqué sews a fabric shape onto the garment and then adds stitching around its edges.')],
            ],
            [
                'slug' => 'how-to-read-a-digitizing-worksheet',
                'title' => 'How to Read a Digitizing Worksheet',
                'summary' => 'Understand the details in a digitizing worksheet before approving your design.',
                'category' => 'Digitizing Services',
                'tone' => 'purple',
                'views' => 1900,
                'helpful' => 40,
                'blocks' => [$p('The worksheet lists stitch count, size, colours and thread sequence. Check the size and colour list before you approve the design.')],
            ],
            [
                'slug' => 'how-to-reduce-puckering-in-embroidery',
                'title' => 'How to Reduce Puckering in Embroidery',
                'summary' => 'Simple steps to keep your embroidery flat and smooth.',
                'category' => 'Embroidery Services',
                'tone' => 'blue',
                'views' => 1200,
                'helpful' => 35,
                'blocks' => [$p('Puckering happens when the stitches pull the fabric together. Use the right stabilizer, keep the design light where possible and hoop the fabric evenly.')],
            ],
            [
                'slug' => 'accepted-file-formats-for-artwork',
                'title' => 'Accepted File Formats for Artwork',
                'summary' => 'See which file formats we accept and recommended settings.',
                'category' => 'File Formats',
                'tone' => 'blue',
                'views' => 1700,
                'helpful' => 55,
                'blocks' => [$p('We accept AI, EPS, PDF and SVG for artwork, and DST, PES or EXP for stitch files. Vector formats are preferred because they scale without losing quality.')],
            ],
        ];
    }
}
