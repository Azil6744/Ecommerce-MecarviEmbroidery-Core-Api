<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FAQHeroSection;
use App\Models\FAQIntroParagraph;
use App\Models\FaqAskQuestionSection;
use App\Models\FAQCategory;
use App\Models\FAQItem;

class FAQSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Hero Section
        FAQHeroSection::updateOrCreate(
            ['id' => 1],
            [
                'badge_title' => 'SUPPORT CENTER',
                'hero_title' => 'Frequently Asked Questions',
                'hero_image' => null,
            ]
        );

        // 2. Intro Paragraph
        FAQIntroParagraph::updateOrCreate(
            ['id' => 1],
            [
                'paragraph_text' => "Find answers to commonly asked questions about our services, products, and processes.\nIf you can't find what you're looking for, feel free to ask us a question.",
            ]
        );

        // 3. Ask Question Section
        FaqAskQuestionSection::updateOrCreate(
            ['id' => 1],
            [
                'heading' => 'Ask a Question',
                'description' => "Can't find what you're looking for? Send us your question and we'll get back to you.",
            ]
        );

        // 4. Categories and Items
        $categoriesData = [
            [
                'category_name' => 'Artwork & Design Requirements',
                'description' => 'Learn about acceptable file formats, artwork specifications, and design guidelines for optimal embroidery quality.',
                'icon' => 'image',
                'order' => 1,
                'items' => [
                    [
                        'question' => 'What file formats do you accept for artwork?',
                        'answer' => 'We accept high-resolution vector and raster formats including AI, EPS, PDF, SVG, PNG, and high-res JPEG (300 DPI minimum).',
                        'icon' => 'file-text',
                        'order' => 1,
                    ],
                    [
                        'question' => 'What is the minimum text size for embroidery?',
                        'answer' => 'For clean legibility, the minimum recommended text height for embroidered lettering is 0.2 inches (approx. 5mm).',
                        'icon' => 'cog',
                        'order' => 2,
                    ],
                    [
                        'question' => 'Can you embroider gradients and shading?',
                        'answer' => 'Embroidery uses physical threads rather than ink. While subtle blends are possible with specialized stitch patterns, solid colors yield the crispest results.',
                        'icon' => 'image',
                        'order' => 3,
                    ],
                    [
                        'question' => 'Do you provide mockups or stitch proofs before production?',
                        'answer' => 'Yes! We provide digital mockups and sew-out proofs for your review and approval before running production.',
                        'icon' => 'bell',
                        'order' => 4,
                    ],
                ]
            ],
            [
                'category_name' => 'Digitizing Services',
                'description' => 'Everything you need to know about converting your artwork into stitch files for embroidery machines.',
                'icon' => 'cog',
                'order' => 2,
                'items' => [
                    [
                        'question' => 'What is embroidery digitizing?',
                        'answer' => 'Digitizing is the specialized process of converting 2D digital graphic files into stitch commands (DST, EMB, PES) that embroidery machines read and execute.',
                        'icon' => 'cog',
                        'order' => 1,
                    ],
                    [
                        'question' => 'Is there a one-time digitizing fee?',
                        'answer' => 'Yes, new custom designs require a one-time digitizing setup fee. Once digitized, your file is saved forever for future reorders with zero setup fee.',
                        'icon' => 'credit-card',
                        'order' => 2,
                    ],
                    [
                        'question' => 'Can I use an existing digitized embroidery file (DST)?',
                        'answer' => 'Yes, if you already possess a production-ready DST or EMB file, you can upload it directly with no digitizing setup fee needed.',
                        'icon' => 'file-text',
                        'order' => 3,
                    ],
                    [
                        'question' => 'How long does digitizing take?',
                        'answer' => 'Standard digitizing turnaround is 24 to 48 hours for sew-out proof generation.',
                        'icon' => 'clock',
                        'order' => 4,
                    ],
                ]
            ],
            [
                'category_name' => 'Embroidery',
                'description' => 'Details about our custom embroidery techniques, thread varieties, materials, and placement options.',
                'icon' => 'shirt',
                'order' => 3,
                'items' => [
                    [
                        'question' => 'What types of garments can you embroider?',
                        'answer' => 'We embroider polos, hats & caps, hoodies, jackets, tote bags, aprons, patches, dress shirts, and athletic wear.',
                        'icon' => 'shirt',
                        'order' => 1,
                    ],
                    [
                        'question' => 'What is 3D Puff Embroidery?',
                        'answer' => '3D Puff uses foam underlay beneath stitches to give hats and outerwear lettering a raised, three-dimensional pop.',
                        'icon' => 'zap',
                        'order' => 2,
                    ],
                    [
                        'question' => 'How many thread colors can I use per design?',
                        'answer' => 'Our industrial multi-head machines support up to 15 vibrant thread colors per design without extra per-color charges.',
                        'icon' => 'image',
                        'order' => 3,
                    ],
                    [
                        'question' => 'Can I supply my own garments for embroidery?',
                        'answer' => 'Yes, we accept customer-supplied apparel provided they are clean and suitable for commercial embroidery hooping.',
                        'icon' => 'refresh-cw',
                        'order' => 4,
                    ],
                ]
            ],
            [
                'category_name' => 'Billing & Payments',
                'description' => 'Payment options, invoicing, terms of service, and refund policy information.',
                'icon' => 'credit-card',
                'order' => 4,
                'items' => [
                    [
                        'question' => 'What payment methods do you accept?',
                        'answer' => 'We accept all major credit cards (Visa, MasterCard, Amex, Discover), PayPal, Apple Pay, Google Pay, and Wire/ACH transfers for corporate accounts.',
                        'icon' => 'credit-card',
                        'order' => 1,
                    ],
                    [
                        'question' => 'When is payment charged?',
                        'answer' => 'Payment is collected upon order placement and design approval before manufacturing begins.',
                        'icon' => 'clock',
                        'order' => 2,
                    ],
                    [
                        'question' => 'Do you offer Net 30 terms for corporate clients?',
                        'answer' => 'Yes, established corporate and institutional accounts can apply for Net 30 terms through our Corporate Accounts program.',
                        'icon' => 'file-text',
                        'order' => 3,
                    ],
                    [
                        'question' => 'What is your refund and return policy?',
                        'answer' => 'Because each embroidered item is custom made, refunds are granted if there is a defect or error in manufacturing or apparel compared to approved proofs.',
                        'icon' => 'refresh-cw',
                        'order' => 4,
                    ],
                ]
            ],
            [
                'category_name' => 'Pricing & Quotes',
                'description' => 'How our pricing works, bulk volume discounts, custom quote requests, and minimum order requirements.',
                'icon' => 'file-text',
                'order' => 5,
                'items' => [
                    [
                        'question' => 'Is there a minimum order quantity (MOQ)?',
                        'answer' => 'We offer flexible minimums! While we handle single prototypes and small runs, volume tier discounts begin at 12+ units.',
                        'icon' => 'shirt',
                        'order' => 1,
                    ],
                    [
                        'question' => 'How is embroidery priced?',
                        'answer' => 'Pricing is determined by the total stitch count of the design, garment style selected, and total order quantity.',
                        'icon' => 'credit-card',
                        'order' => 2,
                    ],
                    [
                        'question' => 'How do I request a custom bulk quote?',
                        'answer' => 'Use our online Quote tool or contact form to submit your design and desired quantities. We provide detailed pricing within 1 business day.',
                        'icon' => 'file-text',
                        'order' => 3,
                    ],
                    [
                        'question' => 'Do you offer volume wholesale discounts?',
                        'answer' => 'Yes! We offer tiered wholesale pricing for quantities over 24, 48, 100, 250, and 500+ units.',
                        'icon' => 'zap',
                        'order' => 4,
                    ],
                ]
            ],
            [
                'category_name' => 'Shipping & Pickup',
                'description' => 'Delivery timeframes, carrier options, tracking your package, and local pickup details.',
                'icon' => 'truck',
                'order' => 6,
                'items' => [
                    [
                        'question' => 'What shipping carriers do you use?',
                        'answer' => 'We ship nationally via FedEx, UPS, and USPS Priority with full door-to-door tracking insurance.',
                        'icon' => 'truck',
                        'order' => 1,
                    ],
                    [
                        'question' => 'Do you offer international shipping?',
                        'answer' => 'Yes, we provide worldwide international shipping with customs clearance support via DHL Express and UPS Worldwide.',
                        'icon' => 'play',
                        'order' => 2,
                    ],
                    [
                        'question' => 'Is local order pickup available?',
                        'answer' => 'Yes! Customers located near our facility can select free Local Facility Pickup at checkout.',
                        'icon' => 'bell',
                        'order' => 3,
                    ],
                    [
                        'question' => 'How do I track my shipment?',
                        'answer' => 'Tracking links are emailed automatically upon dispatch and can be viewed anytime in your account order history.',
                        'icon' => 'file-text',
                        'order' => 4,
                    ],
                ]
            ],
            [
                'category_name' => 'Production & Turnaround',
                'description' => "Learn about our production process, estimated timeframes, and what can affect your order's turnaround time.",
                'icon' => 'clock',
                'order' => 7,
                'items' => [
                    [
                        'question' => 'How long does production take?',
                        'answer' => 'Standard production typically takes 7-10 business days after artwork digitizing approval and payment confirmation. Rush production options are also available if you need your order completed sooner.',
                        'icon' => 'clock',
                        'order' => 1,
                    ],
                    [
                        'question' => 'When does the production timeframe begin?',
                        'answer' => 'The production turnaround clock begins once your final digitizing sew-out proof is approved and payment is processed, not from the date the initial quote was submitted.',
                        'icon' => 'play',
                        'order' => 2,
                    ],
                    [
                        'question' => 'Do you offer rush production?',
                        'answer' => 'Yes! We offer expedited rush production services (2-4 business days) for time-sensitive orders. An additional rush fee applies depending on the order size and schedule availability.',
                        'icon' => 'zap',
                        'order' => 3,
                    ],
                    [
                        'question' => 'Can I request a specific completion date?',
                        'answer' => 'Yes! Please specify your event or in-hands date when placing the order or requesting a quote so our team can plan production and shipping schedules accordingly.',
                        'icon' => 'calendar',
                        'order' => 4,
                    ],
                    [
                        'question' => "What can affect my order's turnaround time?",
                        'answer' => "Factors that may affect turnaround time include complex artwork digitizing revisions, specialty thread or garment availability, order quantity volume, and delays in sample approval.",
                        'icon' => 'cog',
                        'order' => 5,
                    ],
                    [
                        'question' => 'Does shipping time count as part of production time?',
                        'answer' => 'No, shipping transit time is separate from production manufacturing time. Transit times vary by the shipping carrier method chosen at checkout (e.g. Ground vs Express Overnight).',
                        'icon' => 'truck',
                        'order' => 6,
                    ],
                    [
                        'question' => 'Can I check the status of an order while it is in production?',
                        'answer' => 'Yes, you can track real-time status updates directly from your customer account dashboard or by reaching out to our customer support team with your order ID.',
                        'icon' => 'file-text',
                        'order' => 7,
                    ],
                    [
                        'question' => 'How will I know when my order is complete?',
                        'answer' => 'You will receive an automated email notification with courier tracking details as soon as your completed order is inspected, packed, and dispatched from our facility.',
                        'icon' => 'bell',
                        'order' => 8,
                    ],
                    [
                        'question' => 'Are weekends included in the production timeframe?',
                        'answer' => 'No, our production days are calculated strictly on business days (Monday through Friday, excluding major federal holidays).',
                        'icon' => 'calendar',
                        'order' => 9,
                    ],
                    [
                        'question' => 'Can production time change after my order has entered production?',
                        'answer' => 'Changes requested after production has started (such as sizing changes, additional units, or color modifications) may result in production rescheduling and updated estimated completion dates.',
                        'icon' => 'refresh-cw',
                        'order' => 10,
                    ],
                ]
            ],
        ];

        foreach ($categoriesData as $catData) {
            $items = $catData['items'];
            unset($catData['items']);

            $category = FAQCategory::updateOrCreate(
                ['category_name' => $catData['category_name']],
                $catData
            );

            foreach ($items as $itemData) {
                FAQItem::updateOrCreate(
                    [
                        'faq_category_id' => $category->id,
                        'question' => $itemData['question'],
                    ],
                    [
                        'answer' => $itemData['answer'],
                        'icon' => $itemData['icon'] ?? 'clock',
                        'order' => $itemData['order'] ?? 1,
                    ]
                );
            }
        }
    }
}
