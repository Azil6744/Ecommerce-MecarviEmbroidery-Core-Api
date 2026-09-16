<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Roles and Permissions
        $this->call([
            RoleSeeder::class,
        ]);

        // 2. Users and Accounts
        $this->call([
            AdminUserSeeder::class,
            AdditionalAdminSeeder::class,
            SellerAccountSeeder::class,
            UserAccountSeeder::class,
        ]);

        // 3. Page Content and Custom Form Fields
        $this->call([
            QuoteFormFieldSeeder::class,
            ContactPageHeroSectionSeeder::class,
        ]);

        // 4. Products, Storefront, Homepage Content
        $this->call([
            DemoProductSeeder::class,
            TestUserMockDataSeeder::class,
        ]);

        // 5. Admin Settings, Gateways, Email Templates, Shipping, and Sample Orders
        $this->call([
            AdminPagesSeeder::class,
            EmailTemplateSeeder::class,
            DeliveryTimesSeeder::class,
            GlobalAttributeSeeder::class,
            StorePickupLocationsSeeder::class,
            SubscriptionPlanSeeder::class,
            CharitySeeder::class,
        ]);

        // 6. Orders mock data (Proofs, Verifications, Quotations)
        $this->call([
            OrderSectionMockDataSeeder::class,
            OrderProofsAndVerificationsSeeder::class,
        ]);
    }
}
