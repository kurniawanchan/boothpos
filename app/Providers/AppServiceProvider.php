<?php

namespace App\Providers;

use App\Models\License;
use App\Policies\LicenseCatalogPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS if the request is coming through an ngrok proxy
        if (str_contains(request()->url(), 'ngrok-free.dev') || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }

        // 019-billing-system — LicenseCatalogPolicy sengaja TIDAK
        // mengikuti konvensi penamaan otomatis Laravel (License ->
        // LicenseCatalogPolicy, bukan LicensePolicy), supaya tidak
        // bentrok dengan LicenseController milik 018-license-activation
        // (research.md R0). Karena itu harus didaftarkan manual di sini.
        Gate::policy(License::class, LicenseCatalogPolicy::class);
    }
}
