<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 078 / US1 — lalu lintas latar tablet (roster, branding, penyiapan) tidak boleh mengurangi jatah login siapa pun. Dulu semua rute
 * `throttle:N,1` tanpa token berbagi SATU hitungan per IP (kunci = domain|ip, tanpa rute dan tanpa batas), sehingga polling layar PIN
 * menghabiskan jatah 5/menit login web milik owner.
 */
class TabletPollingThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function owner(): User
    {
        return User::factory()->create(['username' => 'owner-tes', 'role' => 'owner', 'password' => Hash::make('benar-123')]);
    }

    private function login(string $password)
    {
        return $this->postJson('/api/v1/auth/login', ['username' => 'owner-tes', 'password' => $password]);
    }

    public function test_heavy_tablet_polling_does_not_consume_the_login_allowance(): void
    {
        $this->owner();
        $outlet = Outlet::first() ?? Outlet::factory()->create();

        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/v1/staff/roster?outlet_id='.$outlet->id)->assertOk();
            $this->getJson('/api/v1/pos-setup/branding')->assertOk();
        }

        $this->login('benar-123')->assertOk();
    }

    public function test_wrong_password_after_polling_is_a_plain_401_not_a_429(): void
    {
        $this->owner();
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/pos-setup/branding')->assertOk();
        }

        $this->login('salah')->assertStatus(401);
    }

    public function test_the_tablet_read_flood_cap_is_300_a_minute_and_answers_in_indonesian(): void
    {
        $this->freezeTime(); // 078 — ratusan permintaan tidak boleh melewati jendela 60 dtk bila suite lambat.
        for ($i = 0; $i < 300; $i++) {
            $this->getJson('/api/v1/pos-setup/branding')->assertOk();
        }

        $res = $this->getJson('/api/v1/pos-setup/branding')->assertStatus(429);

        $res->assertJsonPath('code', 'throttled');
        $this->assertStringStartsWith('Terlalu banyak percobaan. Coba lagi dalam ', $res->json('message'));
        $this->assertGreaterThan(0, $res->json('retry_after'));
        $res->assertHeader('Retry-After');
    }

    public function test_reads_of_different_tablet_routes_share_the_tablet_bucket_but_not_the_login_bucket(): void
    {
        $this->freezeTime(); // 078 — ratusan permintaan tidak boleh melewati jendela 60 dtk bila suite lambat.
        $this->owner();
        $outlet = Outlet::first() ?? Outlet::factory()->create();
        for ($i = 0; $i < 150; $i++) {
            $this->getJson('/api/v1/pos-setup/branding')->assertOk();
            $this->getJson('/api/v1/staff/roster?outlet_id='.$outlet->id)->assertOk();
        }

        // 300 pembacaan tablet sudah terpakai (bucket bersama milik tablet) …
        $this->getJson('/api/v1/pos-setup/outlets')->assertStatus(429);
        // … tetapi login memiliki hitungan sendiri.
        $this->login('benar-123')->assertOk();
    }
}
