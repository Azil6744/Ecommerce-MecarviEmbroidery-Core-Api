<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EcommerceOrderProof;
use App\Models\EcommerceOrderProofComment;
use App\Models\EcommerceOrderVerification;

class OrderProofsAndVerificationsSeeder extends Seeder
{
    public function run(): void
    {
        // Get existing order IDs
        $orders = \App\Models\EcommerceOrder::with('user')->get();

        if ($orders->isEmpty()) {
            $this->command->warn('No orders found. Skipping seeder.');
            return;
        }

        $proofTemplates = [
            [
                'title' => 'Business Card - Final Layout',
                'proof_type' => 'Digital Proof',
                'status' => 'awaiting_approval',
                'version' => 'v3 (3 of 3)',
                'product_name' => 'Premium Business Cards',
                'product_specs' => 'Matte Finish • 350gsm',
                'quantity' => '500 Qty',
                'item_count' => '2 Items',
                'view_mode' => 'Combined in One PDF (Recommended)',
                'internal_notes' => 'Customer requested blue tint on header logo. Adjusted RGB values to match corporate palette.',
                'message_to_customer' => 'Hi John, here is the updated business card design proof with your color corrections. Please review both front and back sides and let us know if everything looks good for printing!',
                'files' => [
                    ['name' => 'Business_Card_Design_v3.jpg', 'size' => '2.4 MB', 'path' => 'proofs/sample-proof-14-a.pdf', 'url' => '/assets/images/order-proof/sample-proof-14-b.png'],
                    ['name' => 'Business_Card_Back.jpg', 'size' => '1.8 MB', 'path' => 'proofs/sample-proof-14-b.png', 'url' => '/assets/images/order-proof/sample-proof-15-b.png'],
                ],
                'comments' => [
                    ['author_type' => 'admin', 'author_name' => 'Alex Morgan (Super Admin)', 'comment' => 'Proof v3 created and submitted for your approval.'],
                    ['author_type' => 'customer', 'author_name' => 'John Doe', 'comment' => 'Checking with our marketing team now, looks very crisp!'],
                ],
            ],
            [
                'title' => 'Flyer A5 Design Layout',
                'proof_type' => 'Digital Proof',
                'status' => 'approved',
                'version' => 'v2 (2 of 2)',
                'product_name' => 'Flyer A5',
                'product_specs' => 'Glossy • 300gsm',
                'quantity' => '1000 Qty',
                'item_count' => '1 Item',
                'view_mode' => 'Combined in One PDF (Recommended)',
                'internal_notes' => 'Customer approved proof via online portal.',
                'message_to_customer' => 'Hello Sarah, your A5 promo flyer artwork is ready for your confirmation. Please inspect margins and text clarity.',
                'files' => [
                    ['name' => 'Flyer_A5_Design.jpg', 'size' => '3.1 MB', 'path' => 'proofs/sample-proof-15-a.pdf', 'url' => '/assets/images/order-proof/sample-proof-16-b.png'],
                ],
                'comments' => [
                    ['author_type' => 'customer', 'author_name' => 'Sarah Miller', 'comment' => 'Looks amazing! We approve this version for immediate print.'],
                    ['author_type' => 'admin', 'author_name' => 'Alex Morgan', 'comment' => 'Thank you Sarah! We are sending this to production.'],
                ],
            ],
            [
                'title' => 'Custom Embroidery Patch & Stickers',
                'proof_type' => 'Embroidery Mockup',
                'status' => 'revision_requested',
                'version' => 'v1 (1 of 3)',
                'product_name' => 'Custom Stickers',
                'product_specs' => 'Vinyl • Waterproof',
                'quantity' => '250 Qty',
                'item_count' => '3 Items',
                'view_mode' => 'Individual Image Gallery',
                'internal_notes' => 'Need to adjust skull badge outline thickness from 1.5mm to 2mm as requested by client.',
                'message_to_customer' => 'Hi Robert, here is the first proof for the sticker sets and embroidered emblems.',
                'files' => [
                    ['name' => 'Sticker_Set_1.jpg', 'size' => '1.2 MB', 'path' => 'proofs/sample-proof-16-a.pdf', 'url' => '/assets/images/order-proof/sample-proof-17-b.png'],
                    ['name' => 'Sticker_Set_2.jpg', 'size' => '1.4 MB', 'path' => 'proofs/sample-proof-16-b.png', 'url' => '/assets/images/order-proof/sample-proof-14-b.png'],
                    ['name' => 'Sticker_Set_3.jpg', 'size' => '1.1 MB', 'path' => 'proofs/sample-proof-17-a.pdf', 'url' => '/assets/images/order-proof/sample-proof-15-b.png'],
                ],
                'comments' => [
                    ['author_type' => 'customer', 'author_name' => 'Robert Wilson', 'comment' => 'Can we make the background yellow a little more golden on Sticker 2?'],
                ],
            ],
            [
                'title' => 'Tri-Fold Corporate Brochure',
                'proof_type' => 'Digital Proof',
                'status' => 'awaiting_approval',
                'version' => 'v2 (2 of 3)',
                'product_name' => 'Tri-Fold Brochure',
                'product_specs' => 'Matte • 250gsm',
                'quantity' => '500 Qty',
                'item_count' => '2 Items',
                'view_mode' => 'Combined in One PDF (Recommended)',
                'internal_notes' => 'Fold lines inspected and verified with cutting plotter template.',
                'message_to_customer' => 'Hi Alice, please inspect inner and outer spread layouts for your tri-fold brochure.',
                'files' => [
                    ['name' => 'Brochure_Inner.jpg', 'size' => '2.6 MB', 'path' => 'proofs/sample-proof-17-a.pdf', 'url' => '/assets/images/order-proof/sample-proof-16-b.png'],
                    ['name' => 'Brochure_Outer.jpg', 'size' => '2.3 MB', 'path' => 'proofs/sample-proof-17-b.png', 'url' => '/assets/images/order-proof/sample-proof-17-b.png'],
                ],
                'comments' => [
                    ['author_type' => 'admin', 'author_name' => 'Alex Morgan', 'comment' => 'Sent proof for client review.'],
                ],
            ],
        ];

        foreach ($orders as $i => $order) {
            $tpl = $proofTemplates[$i % count($proofTemplates)];

            $proof = EcommerceOrderProof::create([
                'order_id'   => $order->id,
                'proof_type' => $tpl['proof_type'],
                'title'      => $tpl['title'],
                'file_path'  => $tpl['files'][0]['path'] ?? ('proofs/sample-proof-' . $order->id . '-a.pdf'),
                'status'     => $tpl['status'],
                'expires_at' => now()->addDays(7),
                'approved_at' => $tpl['status'] === 'approved' ? now()->subDay() : null,
                'rejected_at' => null,
                'reviewed_at' => now()->subHours(5),
                'rejection_reason' => $tpl['status'] === 'revision_requested' ? 'Color adjustment requested on inner spread.' : null,
                'metadata'   => [
                    'version' => $tpl['version'],
                    'proof_version' => $tpl['version'],
                    'product_name' => $tpl['product_name'],
                    'product_specs' => $tpl['product_specs'],
                    'product_quantity' => $tpl['quantity'],
                    'item_count' => $tpl['item_count'],
                    'view_mode' => $tpl['view_mode'],
                    'internal_notes' => $tpl['internal_notes'],
                    'message_to_customer' => $tpl['message_to_customer'],
                    'notify_customer' => true,
                    'allow_customer_reply' => true,
                    'files' => $tpl['files'],
                    'preview_images' => [
                        ['id' => 'front', 'label' => 'Front', 'url' => $tpl['files'][0]['url'] ?? '/assets/images/order-proof/sample-proof-14-b.png'],
                        ['id' => 'back', 'label' => 'Back', 'url' => $tpl['files'][1]['url'] ?? ($tpl['files'][0]['url'] ?? '/assets/images/order-proof/sample-proof-15-b.png')],
                        ['id' => 'close_up', 'label' => 'Close Up', 'url' => '/assets/images/order-proof/sample-proof-16-b.png'],
                        ['id' => 'mockup', 'label' => 'Mockup', 'url' => '/assets/images/order-proof/sample-proof-17-b.png'],
                    ],
                ],
            ]);

            foreach ($tpl['comments'] as $comm) {
                EcommerceOrderProofComment::create([
                    'proof_id' => $proof->id,
                    'user_id' => $comm['author_type'] === 'customer' ? $order->user_id : null,
                    'author_type' => $comm['author_type'],
                    'comment' => $comm['comment'],
                    'created_at' => now()->subHours(random_int(1, 48)),
                ]);
            }
        }

        // --- Order Verifications ---
        $riskLevels  = ['high', 'medium', 'low'];
        $flagReasons = [
            'New customer with large order value ($500+)',
            'Shipping address differs from billing address',
            'Multiple failed payment attempts before success',
            'First-time order with rush delivery requested',
        ];
        $verifStatuses = ['pending', 'reviewing', 'cleared'];

        foreach ($orders as $i => $order) {
            EcommerceOrderVerification::create([
                'order_id'    => $order->id,
                'risk_level'  => $riskLevels[$i % count($riskLevels)],
                'flag_reason' => $flagReasons[$i % count($flagReasons)],
                'status'      => $verifStatuses[$i % count($verifStatuses)],
            ]);
        }

        $this->command->info('Seeded ' . count($orders) . ' rich order proofs and ' . count($orders) . ' order verifications.');
    }
}

