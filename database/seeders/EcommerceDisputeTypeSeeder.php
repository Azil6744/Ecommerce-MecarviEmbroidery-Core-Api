<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EcommerceDisputeType;
use App\Models\EcommerceDisputeQuestion;

class EcommerceDisputeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Damaged / Defective Item',
                'code' => 'damaged_defective',
                'description' => 'Report physical damage, embroidery flaws, broken stitches, or fabric defects.',
                'sort_order' => 1,
                'questions' => [
                    [
                        'label' => 'What is the specific defect or damage?',
                        'field_key' => 'defect_type',
                        'input_type' => 'select',
                        'placeholder' => 'Select defect category',
                        'options' => [
                            'Thread unravelling / broken stitches',
                            'Wrong thread colors used',
                            'Embroidery misalignment / off-center',
                            'Fabric torn, snagged, or punctured',
                            'Stains or discolored material',
                            'Incorrect sizing / dimensions',
                            'Other manufacturing defect',
                        ],
                        'is_required' => true,
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Detailed description of the defect',
                        'field_key' => 'defect_description',
                        'input_type' => 'textarea',
                        'placeholder' => 'Please explain in detail what is wrong with the item...',
                        'is_required' => true,
                        'sort_order' => 2,
                    ],
                    [
                        'label' => 'Upload clear photos showing the damaged areas',
                        'field_key' => 'defect_photos',
                        'input_type' => 'file',
                        'help_text' => 'Take close-up photos in good lighting showing the flaw.',
                        'is_required' => true,
                        'sort_order' => 3,
                    ],
                    [
                        'label' => 'Was the outer packaging/shipping box damaged when delivered?',
                        'field_key' => 'box_damaged',
                        'input_type' => 'radio',
                        'options' => [
                            'Yes, outer box was damaged / crushed',
                            'No, outer box was in good condition',
                        ],
                        'is_required' => false,
                        'sort_order' => 4,
                    ],
                ],
            ],
            [
                'name' => 'Order / Item Not Received',
                'code' => 'not_received',
                'description' => 'The order or package was marked as delivered or has passed estimated delivery, but you have not received it.',
                'sort_order' => 2,
                'questions' => [
                    [
                        'label' => 'Estimated delivery date provided by tracking',
                        'field_key' => 'expected_delivery_date',
                        'input_type' => 'date',
                        'placeholder' => 'YYYY-MM-DD',
                        'is_required' => false,
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Have you checked with neighbors, front desk, or mailbox?',
                        'field_key' => 'checked_surroundings',
                        'input_type' => 'radio',
                        'options' => [
                            'Yes, checked everywhere and carrier says delivered',
                            'Yes, courier confirmed package is lost',
                            'Not yet checked',
                        ],
                        'is_required' => true,
                        'sort_order' => 2,
                    ],
                    [
                        'label' => 'Delivery location details or additional context',
                        'field_key' => 'delivery_context',
                        'input_type' => 'textarea',
                        'placeholder' => 'Provide any relevant details such as gated entry, concierge, or communication with carrier...',
                        'is_required' => false,
                        'sort_order' => 3,
                    ],
                ],
            ],
            [
                'name' => 'Wrong Item Delivered',
                'code' => 'wrong_item',
                'description' => 'Received a completely different item, wrong color, wrong embroidery design, or wrong product.',
                'sort_order' => 3,
                'questions' => [
                    [
                        'label' => 'What did you receive instead of your ordered item?',
                        'field_key' => 'received_item_description',
                        'input_type' => 'text',
                        'placeholder' => 'e.g. Received White Crewneck instead of Black Hoodie',
                        'is_required' => true,
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Upload photo of the received item and barcode label',
                        'field_key' => 'wrong_item_photo',
                        'input_type' => 'file',
                        'help_text' => 'Include both the item and the packaging label if available.',
                        'is_required' => true,
                        'sort_order' => 2,
                    ],
                    [
                        'label' => 'Is the item in original unused condition?',
                        'field_key' => 'item_condition',
                        'input_type' => 'radio',
                        'options' => [
                            'Yes, unopened / with tags in original packaging',
                            'Opened but unused',
                            'Used',
                        ],
                        'is_required' => true,
                        'sort_order' => 3,
                    ],
                ],
            ],
            [
                'name' => 'Missing Items or Incomplete Order',
                'code' => 'missing_items',
                'description' => 'Package arrived, but one or more products or accessories are missing.',
                'sort_order' => 4,
                'questions' => [
                    [
                        'label' => 'Which items or quantities are missing?',
                        'field_key' => 'missing_items_list',
                        'input_type' => 'textarea',
                        'placeholder' => 'List the missing products and quantities...',
                        'is_required' => true,
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Upload photo of the packing slip and package interior',
                        'field_key' => 'packing_slip_photo',
                        'input_type' => 'file',
                        'is_required' => false,
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'name' => 'Billing / Charge Discrepancy',
                'code' => 'billing_discrepancy',
                'description' => 'Issues with payment amount, duplicated charge, or coupon discount not applied.',
                'sort_order' => 5,
                'questions' => [
                    [
                        'label' => 'Type of billing discrepancy',
                        'field_key' => 'billing_issue_type',
                        'input_type' => 'select',
                        'options' => [
                            'Charged wrong total amount',
                            'Charged twice for single order',
                            'Promotional code / discount not applied',
                            'Tax or shipping calculation error',
                            'Other billing issue',
                        ],
                        'is_required' => true,
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Discrepancy details & expected amount',
                        'field_key' => 'billing_explanation',
                        'input_type' => 'textarea',
                        'placeholder' => 'Explain the expected amount vs what was charged on your statement...',
                        'is_required' => true,
                        'sort_order' => 2,
                    ],
                ],
            ],
        ];

        foreach ($types as $typeData) {
            $questions = $typeData['questions'];
            unset($typeData['questions']);

            $disputeType = EcommerceDisputeType::updateOrCreate(
                ['code' => $typeData['code']],
                $typeData
            );

            // Re-sync questions
            $disputeType->questions()->delete();
            foreach ($questions as $q) {
                $disputeType->questions()->create($q);
            }
        }
    }
}
