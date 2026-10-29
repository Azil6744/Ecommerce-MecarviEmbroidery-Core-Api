<?php

namespace Database\Seeders;

use App\Models\GlobalAttribute;
use App\Models\GlobalAttributeValue;
use Illuminate\Database\Seeder;

class GlobalAttributeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clean existing attributes and values to avoid duplicates
        GlobalAttributeValue::query()->delete();
        GlobalAttribute::query()->delete();

        $attributes = [
            // 1. Size (Garment Sizes for Physical & Quotation Products)
            [
                'name' => 'Size',
                'type' => 'Dropdown',
                'description' => 'Standard garment and apparel size options.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 1,
                'values' => [
                    ['name' => 'XS', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'S', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'M', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'L', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                    ['name' => 'XL', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 5],
                    ['name' => '2XL', 'price' => 2.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 6],
                    ['name' => '3XL', 'price' => 3.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 7],
                    ['name' => '4XL', 'price' => 4.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 8],
                ],
            ],

            // 2. Embroidery Placement (Physical & Quotation Products)
            [
                'name' => 'Embroidery Placement',
                'type' => 'Dropdown',
                'description' => 'Where embroidery should be placed on apparel, outerwear or caps.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 2,
                'values' => [
                    ['name' => 'Left Chest', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Right Chest', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Full Back', 'price' => 7.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'Left Sleeve', 'price' => 3.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                    ['name' => 'Right Sleeve', 'price' => 3.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 5],
                    ['name' => 'Cap Front', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 6],
                    ['name' => 'Cap Side', 'price' => 2.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 7],
                    ['name' => 'Cap Back', 'price' => 3.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 8],
                ],
            ],

            // 3. Embroidery Type (Physical & Quotation Products)
            [
                'name' => 'Embroidery Type',
                'type' => 'Dropdown',
                'description' => 'Embroidery stitching technique and style.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 3,
                'values' => [
                    ['name' => 'Flat Embroidery', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => '3D Puff Embroidery', 'price' => 4.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Embroidered Patch', 'price' => 3.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'Applique Embroidery', 'price' => 5.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                ],
            ],

            // 4. Fabric/Material (Physical & Quotation Products)
            [
                'name' => 'Fabric/Material',
                'type' => 'Dropdown',
                'description' => 'Fabric blend and material composition.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 4,
                'values' => [
                    ['name' => '100% Ring-Spun Cotton', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Cotton / Polyester Blend (60/40)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Polyester Performance Dri-Fit', 'price' => 2.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'Heavyweight Cotton Fleece', 'price' => 4.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                    ['name' => 'Denim / Twill Canvas', 'price' => 3.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 5],
                ],
            ],

            // 5. Thread Type (Physical & Quotation Products)
            [
                'name' => 'Thread Type',
                'type' => 'Dropdown',
                'description' => 'Specialty embroidery thread selection.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 5,
                'values' => [
                    ['name' => 'Standard Polyester Thread', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'High-Sheen Rayon Thread', 'price' => 0.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Metallic Gold Thread', 'price' => 1.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'Metallic Silver Thread', 'price' => 1.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                    ['name' => 'Glow in the Dark Thread', 'price' => 2.50, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 5],
                ],
            ],

            // 6. Digitizing Option (Physical & Quotation Products)
            [
                'name' => 'Digitizing Option',
                'type' => 'Dropdown',
                'description' => 'Logo digitizing and vector conversion services.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 6,
                'values' => [
                    ['name' => 'Standard Logo Digitizing (Included)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Complex Logo Digitizing (>15k Stitches)', 'price' => 15.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Rush 12hr Digitizing Service', 'price' => 20.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'Vector Artwork Redraw / Cleanup', 'price' => 25.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                ],
            ],

            // 7. Size / Dimensions (Digital Products)
            [
                'name' => 'Size / Dimensions',
                'type' => 'Dropdown',
                'description' => 'Embroidery machine hoop size and dimensions for digital design files.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 7,
                'values' => [
                    ['name' => 'Small - 4" x 4" (100x100mm Hoop)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Medium - 5" x 7" (130x180mm Hoop)', 'price' => 2.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Large - 6" x 10" (160x260mm Hoop)', 'price' => 4.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'Jumbo Jacket Back - 8" x 12" (200x300mm)', 'price' => 8.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                ],
            ],

            // 8. Orientation (Digital Products)
            [
                'name' => 'Orientation',
                'type' => 'Dropdown',
                'description' => 'Artwork orientation and aspect layout.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 8,
                'values' => [
                    ['name' => 'Landscape', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Portrait', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Square / Centered', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                ],
            ],

            // 9. Design Sides (Digital Products)
            [
                'name' => 'Design Sides',
                'type' => 'Dropdown',
                'description' => 'Number of placement sides and stitch locations.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 9,
                'values' => [
                    ['name' => 'Front Only (Single Location)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Front & Back (Dual Locations)', 'price' => 5.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Multi-Position (3+ Locations)', 'price' => 10.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                ],
            ],

            // 10. Source File Format (Digital Products)
            [
                'name' => 'Source File Format',
                'type' => 'Dropdown',
                'description' => 'Machine file format for embroidery equipment.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 10,
                'values' => [
                    ['name' => 'DST (Tajima / Universal)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'PES (Brother / Baby Lock)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'EMB (Wilcom Native Working File)', 'price' => 5.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                    ['name' => 'EXP (Melco / Bernina)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 4],
                    ['name' => 'JEF (Janome)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 5],
                    ['name' => 'All Formats Bundle + PDF Worksheet', 'price' => 3.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 6],
                ],
            ],

            // 11. Turnaround Time (Digital & Physical Fulfillment)
            [
                'name' => 'Turnaround Time',
                'type' => 'Dropdown',
                'description' => 'Production and delivery turnaround timeframe.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 11,
                'values' => [
                    ['name' => 'Standard Delivery (24 Hours)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Rush Express (12 Hours)', 'price' => 10.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Urgent Same-Day (4-6 Hours)', 'price' => 20.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                ],
            ],

            // 12. Design Information & Notes (Digital Products)
            [
                'name' => 'Design Information & Notes',
                'type' => 'Dropdown',
                'description' => 'Design instructions and production requirements.',
                'pricing_mode' => 'per_item',
                'status' => 'active',
                'sort_order' => 12,
                'values' => [
                    ['name' => 'Target Garment / Fabric Notes', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 1],
                    ['name' => 'Specific Thread Color Codes (Pantone/Madeira)', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 2],
                    ['name' => 'Maximum Width/Height Restriction Notes', 'price' => 0.00, 'pricing_mode' => 'per_item', 'status' => 'active', 'sort_order' => 3],
                ],
            ],
        ];

        foreach ($attributes as $attrData) {
            $values = $attrData['values'];
            unset($attrData['values']);

            $attribute = GlobalAttribute::create($attrData);
            if (!empty($values)) {
                $attribute->values()->createMany($values);
            }
        }
    }
}
