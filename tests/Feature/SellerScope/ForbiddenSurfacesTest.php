<?php

namespace Tests\Feature\SellerScope;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** 058 — permukaan administratif / lintas-seller ditolak (403 seller_scope_forbidden); sisanya tetap terbuka. */
class ForbiddenSurfacesTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    /** Rute yang BOLEH tetap dibuka pengguna ber-seller (data bersama / sudah di-scope di tempat lain). */
    private const SCOPE_AWARE_SEGMENTS = [
        'auth', 'license', 'server-info', 'settings', 'products', 'variants', 'catalog', 'stock', 'bom', 'orders',
        'preorders', 'customers', 'purchase-orders', 'reports', 'settlements', 'sessions', 'pos-drafts',
        'payment-proofs', 'payment-channels', 'events', 'categories', 'artists', 'vendors', 'materials', 'shipments',
        // Customer commerce — rute etalase/pelanggan: pengguna staf (ber-seller atau tidak) ditolak oleh guard, bukan
        // oleh seller scope. Token staf tidak pernah diterima di sana (lihat CustomerTokenBoundaryTest).
        'storefront', 'customer',
    ];

    public function test_admin_only_surfaces_are_refused_for_a_scoped_user(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        foreach (['/users', '/roles', '/settings', '/settings/payment', '/backups', '/activity-logs', '/exports/users',
            '/reports/sales-by-payment-method', '/companies', '/invoices', '/licenses', '/customers/export'] as $uri) {
            $this->getJson("/api/v1{$uri}")->assertForbidden()->assertJsonPath('code', 'seller_scope_forbidden');
        }

        $this->postJson('/api/v1/imports/master-data', [])->assertForbidden()->assertJsonPath('code', 'seller_scope_forbidden');
        $this->putJson('/api/v1/settings', [])->assertForbidden()->assertJsonPath('code', 'seller_scope_forbidden');
        $this->postJson('/api/v1/events', ['name' => 'x'])->assertForbidden()->assertJsonPath('code', 'seller_scope_forbidden');
        $this->postJson('/api/v1/artists', ['name' => 'x', 'code' => 'ZZZ'])->assertForbidden()->assertJsonPath('code', 'seller_scope_forbidden');
        $this->postJson('/api/v1/preorders/import', [])->assertForbidden()->assertJsonPath('code', 'seller_scope_forbidden');
    }

    public function test_shared_reference_data_stays_open_to_a_scoped_user(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        foreach (['/settings/features', '/vendors', '/materials', '/payment-channels', '/events', '/categories', '/auth/me'] as $uri) {
            $this->getJson("/api/v1{$uri}")->assertOk();
        }
    }

    public function test_the_same_surfaces_work_for_a_user_without_a_seller_and_for_the_owner(): void
    {
        $this->buildSellerWorld();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        foreach (['/users', '/roles', '/settings', '/backups', '/activity-logs', '/reports/sales-by-payment-method'] as $uri) {
            $this->assertNotSame(403, $this->getJson("/api/v1{$uri}")->status(), $uri);
        }
    }

    /** Jaring pengaman: rute BARU harus diklasifikasi (di-scope di tempat lain, atau ditutup seller.forbidden). */
    public function test_every_api_route_is_classified_for_seller_scope(): void
    {
        $unclassified = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            $segment = explode('/', substr($uri, 7))[0];

            if (in_array('seller.forbidden', $route->gatherMiddleware(), true) || in_array($segment, self::SCOPE_AWARE_SEGMENTS, true)) {
                continue;
            }
            $unclassified[] = implode('|', $route->methods()).' '.$uri;
        }

        $this->assertSame([], $unclassified, "Rute belum diklasifikasi untuk seller scope (tambahkan seller.forbidden atau daftarkan segmennya di SCOPE_AWARE_SEGMENTS setelah dipastikan ter-scope):\n".implode("\n", $unclassified));
    }
}
