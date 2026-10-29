<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Category;
use Illuminate\Support\Str;

$categoriesTree = [
    [
        'name' => 'Apparel',
        'slug' => 'apparel',
        'description' => 'Embroidered apparel for every team, brand or event.',
        'sort_order' => 1,
        'image' => '/megamenu/apparel_art@2x.png',
        'children' => [
            'T-Shirts',
            'Polo Shirts',
            'Button Up Shirts',
            'Hoodies & Sweatshirts',
            'Jackets & Coats',
            'Sweaters',
            'Vests',
            'Activewear',
            'Workwear',
            'Safety Apparel',
            'Youth Apparel',
            'Uniforms',
        ]
    ],
    [
        'name' => 'Headwear',
        'slug' => 'headwear',
        'description' => 'Custom embroidered hats for any occasion.',
        'sort_order' => 2,
        'image' => '/megamenu/headwear_art@2x.png',
        'children' => [
            'Snapback Hats',
            'Fitted Hats',
            'Dad Hats',
            'Trucker Hats',
            'Beanies',
            'Bucket Hats',
            'Visors',
            'Boonie Hats',
        ]
    ],
    [
        'name' => 'Bags & Accessories',
        'slug' => 'bags-accessories',
        'description' => 'Functional and stylish embroidered accessories.',
        'sort_order' => 3,
        'image' => '/megamenu/bags_art@2x.png',
        'children' => [
            'Backpacks',
            'Duffel Bags',
            'Tote Bags',
            'Drawstring Bags',
            'Laptop Bags',
            'Coolers & Lunch Bags',
            'Aprons',
            'Towels',
            'Blankets',
            'Lanyards',
            'Keychains',
        ]
    ],
    [
        'name' => 'Patches & Emblems',
        'slug' => 'patches-emblems',
        'description' => 'Custom embroidered patches for branding, teams and uniforms.',
        'sort_order' => 4,
        'image' => '/megamenu/patches_art@2x.png',
        'children' => [
            'Embroidered Patches',
            'Appliqué Patches',
            'PVC Patches',
            'Chenille Patches',
            'Name Patches',
            'Logo Patches',
            'Iron-On Patches',
            'Velcro Patches',
            'Emblems & Crests',
        ]
    ],
    [
        'name' => 'Hardware & Display',
        'slug' => 'hardware-display',
        'description' => 'Displays, frames and hardware for your embroidered products.',
        'sort_order' => 5,
        'image' => '/megamenu/hardware_art@2x.png',
        'children' => [
            'A Frame Hardware',
            'Backdrop Stand Hardware',
            'Banner Stand Hardware',
            'Flag Hardware',
            'Sign Post Hardware',
            'Display Accessories',
            'Hanging Systems',
            'Replacement Parts',
        ]
    ],
    [
        'name' => 'Business & Uniforms',
        'slug' => 'business-uniforms',
        'description' => 'Professional embroidered solutions for your business.',
        'sort_order' => 6,
        'image' => '/megamenu/business_art@2x.png',
        'children' => [
            'Company Uniforms',
            'Team Apparel',
            'Staff Shirts',
            'Workwear',
            'Safety Wear',
            'Healthcare Uniforms',
            'Hospitality Uniforms',
            'School Uniforms',
            'Church Apparel',
            'Sports Teams',
            'Custom Orders',
        ]
    ],
];

// Give any other root categories higher sort order so the primary 6 stay at top
Category::whereNull('parent_id')
    ->whereNotIn('slug', ['apparel', 'headwear', 'bags-accessories', 'patches-emblems', 'hardware-display', 'business-uniforms'])
    ->where('sort_order', '<', 10)
    ->update(['sort_order' => 10]);

foreach ($categoriesTree as $pIndex => $parentData) {
    $parent = Category::updateOrCreate(
        ['slug' => $parentData['slug']],
        [
            'name' => $parentData['name'],
            'description' => $parentData['description'],
            'is_active' => true,
            'sort_order' => $parentData['sort_order'],
            'image' => $parentData['image'],
            'parent_id' => null,
        ]
    );
    echo "Parent Category: {$parent->name} (ID: {$parent->id})\n";

    foreach ($parentData['children'] as $cIndex => $childName) {
        $childSlug = Str::slug($parentData['slug'] . '-' . $childName);
        $child = Category::updateOrCreate(
            ['slug' => $childSlug],
            [
                'name' => $childName,
                'description' => "{$childName} under {$parent->name}",
                'is_active' => true,
                'sort_order' => $cIndex + 1,
                'parent_id' => $parent->id,
            ]
        );
        echo "  - Subcategory: {$child->name} (ID: {$child->id}, Slug: {$child->slug})\n";
    }
}

echo "All real categories seeded successfully!\n";
