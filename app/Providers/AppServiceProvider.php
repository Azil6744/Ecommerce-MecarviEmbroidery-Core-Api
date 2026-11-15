<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Membership actions → in-app / SMS notifications for the member.
        \App\Models\EcommerceMembershipActivity::observe(\App\Observers\MembershipActivityObserver::class);

        $options = [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
            'verify' => false, // Bypass SSL certificate verification for all internal loopback requests
        ];

        \Illuminate\Support\Facades\Http::globalOptions($options);
    }
}
