<?php

namespace App\Providers;

use App\Models\CustomerAccount;
use App\Models\License;
use App\Policies\LicenseCatalogPolicy;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqlCliDumper;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pembungkus mysqldump/mysql untuk cadangan & pemulihan; test mengganti
        // binding ini dengan fake (lihat DatabaseDumper untuk alasannya).
        $this->app->bind(DatabaseDumper::class, fn () => MysqlCliDumper::fromConfig());
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

        $this->registerCustomerCommerceRateLimiters();
    }

    /**
     * Customer commerce (CONTRACT §3) — limiter BERNAMA untuk rute /customer dan
     * /storefront. Angkanya (per menit) dibaca dari config/customer_commerce.php
     * saat permintaan datang.
     *
     * Kenapa bernama dan berawalan sendiri: `throttle:5,1` tanpa nama (login staf)
     * memakai kunci IP / id pengguna TANPA komponen rute. Kalau login pelanggan
     * memakainya juga, semua pelanggan di balik satu IP berbagi ember dengan
     * kasir, dan staf ber-id 5 berbagi ember dengan akun pelanggan ber-id 5.
     *
     * Register dan login dibatasi dua lapis: per IP (banjir dari satu sumber) dan
     * per e-mail ter-normalisasi (tebak kata sandi satu akun dari banyak IP).
     * E-mail di-sha1 supaya data pribadi tidak menjadi kunci cache.
     */
    private function registerCustomerCommerceRateLimiters(): void
    {
        $perMinute = fn (string $key): int => max(1, (int) config("customer_commerce.rate_limits.{$key}"));

        $byIpAndEmail = function (string $name, string $config) use ($perMinute) {
            RateLimiter::for($name, function (Request $request) use ($name, $config, $perMinute) {
                $limits = [Limit::perMinute($perMinute("{$config}.per_ip"))->by("{$name}|ip|{$request->ip()}")];

                $email = $request->input('email');
                if (is_string($email) && $email !== '') {
                    $limits[] = Limit::perMinute($perMinute("{$config}.per_email"))
                        ->by("{$name}|email|".sha1(CustomerAccount::normaliseEmail($email)));
                }

                return $limits;
            });
        };

        $byIpAndEmail('customer-register', 'register');
        $byIpAndEmail('customer-login', 'login');

        RateLimiter::for('storefront', fn (Request $request) => Limit::perMinute($perMinute('storefront.per_ip'))
            ->by("storefront|ip|{$request->ip()}"));

        // Per AKUN (bukan per IP): pelanggan di jaringan yang sama tidak saling menghabiskan jatah.
        // Dipasang setelah `auth:customer`, jadi pengguna guard `customer` sudah ter-resolve.
        $byAccount = function (string $name, string $config) use ($perMinute) {
            RateLimiter::for($name, function (Request $request) use ($name, $config, $perMinute) {
                $accountId = $request->user('customer')?->getAuthIdentifier();

                return Limit::perMinute($perMinute("{$config}.per_account"))
                    ->by($accountId !== null ? "{$name}|account|{$accountId}" : "{$name}|ip|{$request->ip()}");
            });
        };

        $byAccount('customer-api', 'customer_api');
        $byAccount('customer-checkout', 'checkout');
    }
}
