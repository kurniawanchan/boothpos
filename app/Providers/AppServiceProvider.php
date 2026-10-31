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
        // 087 — peringatan bahan minus dari penyelesaian pesanan, per permintaan.
        $this->app->scoped(\App\Support\MaterialWarningBag::class);

        // Pembungkus mysqldump/mysql untuk cadangan & pemulihan; test mengganti
        // binding ini dengan fake (lihat DatabaseDumper untuk alasannya).
        $this->app->bind(DatabaseDumper::class, fn () => MysqlCliDumper::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 078 — pembatas BERNAMA, masing-masing dengan hitungan sendiri. Pembatas numerik (`throttle:N,1`) memakai kunci
        // `domain|ip` TANPA rute dan tanpa batas, sehingga semua rute tanpa token dari satu IP berbagi satu hitungan: polling
        // layar PIN tablet (±12 permintaan/menit) menghabiskan jatah 5/menit login web. Pembatas bernama diberi kunci
        // `md5(nama . kunci)`, jadi bucket-nya terpisah dengan sendirinya.
        // Login password dihitung per akun hanya untuk percobaan GAGAL (AuthController::login); ini hanya batas banjir per IP.
        \Illuminate\Support\Facades\RateLimiter::for('login-flood', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by((string) $r->ip()));
        // Pembacaan latar tablet (roster, branding, outlet, terminal, alamat server): hanya penahan banjir, BUKAN kontrol akses.
        // ±10 tablet × 8–12 permintaan/menit di balik satu IP tetap jauh di bawah 300.
        \Illuminate\Support\Facades\RateLimiter::for('tablet-read', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(300)->by((string) $r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('tablet-write', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by((string) $r->ip()));
        // Login PIN: dua penahan banjir — per orang per tempat (20/mnt) DAN total per IP (30/mnt), supaya satu IP tidak bisa mencoba banyak
        // `user_id` bergantian tanpa batas. Rem sebenarnya tetap kunci 5-salah per pengguna di PinAuthService.
        \Illuminate\Support\Facades\RateLimiter::for('pin-login', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by($r->ip().'|'.$r->input('outlet_id').'|'.$r->input('user_id')),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('ip:'.$r->ip()),
        ]);

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
