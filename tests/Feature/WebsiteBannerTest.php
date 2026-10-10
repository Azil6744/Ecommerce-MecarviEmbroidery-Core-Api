<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\WebsiteBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebsiteBannerTest extends TestCase
{
    public function test_can_fetch_all_website_banners_with_defaults()
    {
        $response = $this->getJson('/api/v1/website-banners');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'banners' => [
                    'home' => [
                        'page_key',
                        'page_name',
                        'category',
                        'title',
                        'subtitle',
                        'description',
                        'theme_color',
                        'accent_color',
                        'is_active',
                    ],
                ],
            ],
        ]);
    }

    public function test_can_fetch_single_website_banner_by_key()
    {
        $response = $this->getJson('/api/v1/website-banners/home');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'banner' => [
                    'page_key' => 'home',
                ],
            ],
        ]);
    }

    public function test_can_save_and_update_website_banner()
    {
        $payload = [
            'page_key' => 'products',
            'title' => 'Customized Product Title',
            'subtitle' => 'Special Summer Discounts',
            'description' => 'Updated embroidery catalog details.',
            'theme_color' => '#dc2626',
            'accent_color' => '#ef4444',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/website-banners/products', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'banner' => [
                    'page_key' => 'products',
                    'title' => 'Customized Product Title',
                    'subtitle' => 'Special Summer Discounts',
                    'theme_color' => '#dc2626',
                ],
            ],
        ]);

        $this->assertDatabaseHas('website_banners', [
            'page_key' => 'products',
            'title' => 'Customized Product Title',
            'theme_color' => '#dc2626',
        ]);
    }

    public function test_can_reset_website_banner_to_defaults()
    {
        // First customize
        $this->postJson('/api/v1/website-banners/products', [
            'page_key' => 'products',
            'title' => 'Customized Product Title',
        ]);

        // Then reset
        $response = $this->postJson('/api/v1/website-banners/products/reset');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'banner' => [
                    'page_key' => 'products',
                    'is_customized' => false,
                ],
            ],
        ]);

        $this->assertDatabaseMissing('website_banners', [
            'page_key' => 'products',
        ]);
    }
}
