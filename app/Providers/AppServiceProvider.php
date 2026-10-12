<?php

namespace App\Providers;

use App\Models\License;
use App\Policies\LicenseCatalogPolicy;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqlCliDumper;
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
        // 045 Stage 2 — satu konteks outlet per permintaan (scoped, bukan singleton, supaya tidak bocor
        // antar-permintaan pada worker jangka panjang).
        $this->app->scoped(\App\Support\OutletContext::class);
        $this->app->scoped(\App\Support\OutletPriceBook::class);

        // Pembungkus mysqldump/mysql untuk cadangan & pemulihan; test mengganti
        // binding ini dengan fake (lihat DatabaseDumper untuk alasannya).
        $this->app->bind(DatabaseDumper::class, fn () => MysqlCliDumper::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 045 Stage 3 — relasi outlet × produk dibuat otomatis untuk SETIAP jalur pembuatan produk/outlet.
        \App\Models\Product::created(fn ($product) => app(\App\Services\OutletMenuService::class)->materializeForProduct($product));
        \App\Models\Outlet::created(fn ($outlet) => app(\App\Services\OutletMenuService::class)->materializeForOutlet($outlet));

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
