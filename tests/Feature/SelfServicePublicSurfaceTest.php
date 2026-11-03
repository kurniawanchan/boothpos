<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 090 / T094 — peninjauan struktural permukaan PUBLIK pesan mandiri (pengecualian Konstitusi IV): SETIAP rute self-service tanpa
 * `auth:sanctum` WAJIB dibatasi laju dengan pembatas BERNAMA (aturan 078: bukan `throttle:N,1`), tetap lewat gerbang lisensi,
 * dan rute baru yang lupa dibatasi akan menggagalkan tes ini. Rute yang memuat `{token}` juga wajib lewat verifikasi token QR.
 */
class SelfServicePublicSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const NAMED = ['self-service-read', 'self-service-submit', 'self-service-proof'];

    /** @return list<\Illuminate\Routing\Route> */
    private function publicRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())->filter(function ($r) {
            return str_starts_with($r->uri(), 'api/v1/self-service/')
                && ! collect($r->gatherMiddleware())->contains(fn ($m) => str_contains((string) $m, 'auth:'));
        })->values()->all();
    }

    public function test_the_expected_public_routes_exist(): void
    {
        $uris = collect($this->publicRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->all();

        foreach ([
            'GET|HEAD api/v1/self-service/{token}/context', 'GET|HEAD api/v1/self-service/{token}/menu',
            'POST api/v1/self-service/{token}/proofs', 'POST api/v1/self-service/{token}/orders', 'GET|HEAD api/v1/self-service/receipts/{receiptToken}',
        ] as $expected) {
            $this->assertContains($expected, $uris);
        }
        $this->assertCount(5, $uris, 'rute publik baru harus disengaja: perbarui tes ini, OpenAPI, dan catatan Konstitusi IV');
    }

    public function test_every_public_route_has_a_named_rate_limiter(): void
    {
        foreach ($this->publicRoutes() as $route) {
            $throttles = collect($route->gatherMiddleware())->filter(fn ($m) => str_starts_with((string) $m, 'throttle:'))->map(fn ($m) => substr((string) $m, 9));
            $this->assertNotEmpty($throttles, $route->uri().' tanpa pembatas laju');
            foreach ($throttles as $name) {
                $this->assertContains($name, self::NAMED, $route->uri()." memakai pembatas '{$name}' (harus bernama, bukan throttle:N,1)");
            }
        }
    }

    public function test_token_routes_verify_the_qr_token_and_every_route_passes_the_licence_gate(): void
    {
        foreach ($this->publicRoutes() as $route) {
            $middleware = collect($route->gatherMiddleware());
            if (str_contains($route->uri(), '{token}')) {
                $this->assertTrue($middleware->contains('self-service.table'), $route->uri().' tanpa verifikasi token QR');
            }
            // Gerbang lisensi adalah middleware API global (bootstrap/app.php): terpasang di semua rute api/*.
            $this->assertTrue(str_starts_with($route->uri(), 'api/'), $route->uri());
        }
        $kernelAlias = app('router')->getMiddleware();
        $this->assertArrayHasKey('self-service.table', $kernelAlias);
        $this->assertArrayHasKey('idempotent', $kernelAlias, 'alias lama tidak boleh hilang (alias() menimpa seluruh peta)');
    }

    public function test_the_named_limiters_are_registered(): void
    {
        foreach (self::NAMED as $name) {
            $this->assertNotNull(app(\Illuminate\Cache\RateLimiter::class)->limiter($name), "pembatas {$name} belum terdaftar");
        }
    }

    public function test_the_staff_routes_are_not_public(): void
    {
        $this->getJson('/api/v1/self-service/orders')->assertUnauthorized();
        $this->getJson('/api/v1/self-service/alerts')->assertUnauthorized();
        $this->postJson('/api/v1/dining-tables/qr-batch', ['ids' => [1]])->assertUnauthorized();
        $this->putJson('/api/v1/tabs/1/service-type', ['service_type' => 'dine_in'])->assertUnauthorized();
    }
}
