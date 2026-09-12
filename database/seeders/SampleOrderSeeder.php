<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderItem;
use App\Models\EcommerceReturn;
use Illuminate\Support\Carbon;

class SampleOrderSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) {
            return;
        }

        foreach ($users as $user) {
            $order1 = EcommerceOrder::updateOrCreate(
                ['order_number' => 'ORD-2026-8891'],
                [
                    'user_id' => $user->id,
                    'customer_name' => $user->name ?: 'Azil Adil',
                    'customer_email' => $user->email,
                    'customer_phone' => $user->phone ?: '+1 (404) 555-0198',
                    'company_name' => 'Mecarvi Creations',
                    'status' => 'delivered',
                    'payment_status' => 'paid',
                    'payment_method' => 'Visa ending in 4242',
                    'shipping_method' => 'UPS Ground',
                    'currency' => 'USD',
                    'subtotal' => 450.00,
                    'shipping_amount' => 15.00,
                    'total_amount' => 465.00,
                    'order_date' => Carbon::now()->subDays(5),
                    'created_at' => Carbon::now()->subDays(5),
                    'shipped_at' => Carbon::now()->subDays(3),
                    'delivered_at' => Carbon::now()->subDays(1),
                    'estimated_delivery_at' => Carbon::now()->subDays(1),
                    'shipping_address' => "742 Evergreen Terrace\nSuite 4B\nAtlanta, GA 30301\nUnited States",
                    'billing_address' => "742 Evergreen Terrace\nSuite 4B\nAtlanta, GA 30301\nUnited States",
                    'tracking_carrier' => 'UPS',
                    'tracking_number' => '1Z999AA10123456784',
                    'tracking_url' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
                ]
            );

            $order1->items()->delete();
            $order1->items()->createMany([
                [
                    'product_name' => 'Custom Embroidered Pullover Hoodie',
                    'product_sku' => 'HOOD-EMB-01',
                    'quantity' => 2,
                    'unit_price' => 140.00,
                    'total_price' => 280.00,
                    'product_options' => ['color' => 'Forest Green', 'size' => 'L', 'decoration' => 'Full Chest Embroidery'],
                ],
                [
                    'product_name' => 'Classic Snapback Embroidered Cap',
                    'product_sku' => 'CAP-EMB-02',
                    'quantity' => 1,
                    'unit_price' => 45.00,
                    'total_price' => 45.00,
                    'product_options' => ['color' => 'Midnight Black', 'size' => 'Adjustable', 'decoration' => 'Front 3D Puff'],
                ],
                [
                    'product_name' => 'Premium Organic Canvas Tote Bag',
                    'product_sku' => 'TOTE-EMB-03',
                    'quantity' => 3,
                    'unit_price' => 25.00,
                    'total_price' => 75.00,
                    'product_options' => ['color' => 'Natural Beige', 'size' => 'One Size', 'decoration' => 'Center Logo'],
                ],
                [
                    'product_name' => 'Embroidered Pique Polo Shirt',
                    'product_sku' => 'POLO-EMB-04',
                    'quantity' => 1,
                    'unit_price' => 50.00,
                    'total_price' => 50.00,
                    'product_options' => ['color' => 'Navy Blue', 'size' => 'M', 'decoration' => 'Left Chest Logo'],
                ],
            ]);

            $order1->statusEvents()->delete();
            $order1->statusEvents()->createMany([
                ['status' => 'confirmed', 'label' => 'Order Placed & Verified', 'created_at' => Carbon::now()->subDays(5)],
                ['status' => 'processing', 'label' => 'Embroidery Digitization & Review', 'created_at' => Carbon::now()->subDays(4)],
                ['status' => 'shipped', 'label' => 'Handed over to UPS Courier', 'created_at' => Carbon::now()->subDays(3)],
                ['status' => 'out_for_delivery', 'label' => 'Out for Local Delivery', 'created_at' => Carbon::now()->subDays(1)->subHours(5)],
                ['status' => 'delivered', 'label' => 'Package Delivered to Front Porch', 'created_at' => Carbon::now()->subDays(1)],
            ]);

            // Seed a second order in transit
            $order2 = EcommerceOrder::updateOrCreate(
                ['order_number' => 'ORD-2026-9420'],
                [
                    'user_id' => $user->id,
                    'customer_name' => $user->name ?: 'Azil Adil',
                    'customer_email' => $user->email,
                    'customer_phone' => $user->phone ?: '+1 (404) 555-0198',
                    'company_name' => 'Mecarvi Creations',
                    'status' => 'shipped',
                    'payment_status' => 'paid',
                    'payment_method' => 'Mastercard ending in 5521',
                    'shipping_method' => 'FedEx Express (2-Day)',
                    'currency' => 'USD',
                    'subtotal' => 280.00,
                    'shipping_amount' => 20.00,
                    'total_amount' => 300.00,
                    'order_date' => Carbon::now()->subDays(2),
                    'created_at' => Carbon::now()->subDays(2),
                    'shipped_at' => Carbon::now()->subDay(),
                    'estimated_delivery_at' => Carbon::now()->addDays(2),
                    'shipping_address' => "742 Evergreen Terrace\nSuite 4B\nAtlanta, GA 30301\nUnited States",
                    'billing_address' => "742 Evergreen Terrace\nSuite 4B\nAtlanta, GA 30301\nUnited States",
                    'tracking_carrier' => 'FedEx',
                    'tracking_number' => '785423961287',
                    'tracking_url' => 'https://www.fedex.com/fedextrack/?trknbr=785423961287',
                ]
            );

            $order2->items()->delete();
            $order2->items()->createMany([
                [
                    'product_name' => 'Custom Zip-Up Fleece Jacket',
                    'product_sku' => 'JKT-EMB-09',
                    'quantity' => 2,
                    'unit_price' => 140.00,
                    'total_price' => 280.00,
                    'product_options' => ['color' => 'Charcoal Heather', 'size' => 'XL', 'decoration' => 'Back Logo Embroidery'],
                ],
            ]);

            $order2->statusEvents()->delete();
            $order2->statusEvents()->createMany([
                ['status' => 'confirmed', 'label' => 'Order Placed & Confirmed', 'created_at' => Carbon::now()->subDays(2)],
                ['status' => 'processing', 'label' => 'Embroidery in Production', 'created_at' => Carbon::now()->subDays(1)->subHours(8)],
                ['status' => 'shipped', 'label' => 'Departed FedEx Facility', 'created_at' => Carbon::now()->subDay()],
            ]);
        }
    }
}
