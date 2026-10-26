<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 078 / US1 — login password dihitung PER AKUN (username + IP) dan hanya untuk percobaan GAGAL: 5 per menit, lalu 429 berbahasa
 * Indonesia dengan lama tunggu. Akun lain dari jaringan yang sama tidak terpengaruh, dan username yang tidak ada diperlakukan sama
 * persis (tidak membocorkan akun mana yang ada).
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        User::factory()->create(['username' => 'owner-tes', 'role' => 'owner', 'password' => Hash::make('benar-123')]);
        User::factory()->create(['username' => 'admin-tes', 'role' => 'admin', 'password' => Hash::make('benar-456')]);
    }

    private function login(string $user, string $password)
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user, 'password' => $password]);
    }

    public function test_the_sixth_attempt_is_refused_with_an_indonesian_message_and_wait_time_even_if_correct(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('owner-tes', 'salah')->assertStatus(401);
        }

        $res = $this->login('owner-tes', 'benar-123')->assertStatus(429);

        $res->assertJsonPath('code', 'throttled');
        $this->assertMatchesRegularExpression('/^Terlalu banyak percobaan\. Coba lagi dalam \d+ detik\.$/', $res->json('message'));
        $this->assertGreaterThan(0, $res->json('retry_after'));
        $res->assertHeader('Retry-After');
    }

    public function test_another_account_from_the_same_address_is_not_affected(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('owner-tes', 'salah')->assertStatus(401);
        }
        $this->login('owner-tes', 'salah')->assertStatus(429);

        $this->login('admin-tes', 'benar-456')->assertOk();
    }

    public function test_an_unknown_username_is_counted_and_answered_like_a_wrong_password(): void
    {
        $known = $this->login('owner-tes', 'salah');
        $unknown = $this->login('tidak-ada', 'salah');

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));

        for ($i = 0; $i < 4; $i++) {
            $this->login('tidak-ada', 'salah')->assertStatus(401);
        }
        $this->login('tidak-ada', 'salah')->assertStatus(429);
    }

    public function test_a_successful_login_clears_the_failed_attempts(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('owner-tes', 'salah')->assertStatus(401);
        }
        $this->login('owner-tes', 'benar-123')->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->login('owner-tes', 'salah')->assertStatus(401);
        }
        $this->login('owner-tes', 'benar-123')->assertOk();
    }

    public function test_the_correct_password_works_again_after_the_window(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('owner-tes', 'salah')->assertStatus(401);
        }
        $this->login('owner-tes', 'benar-123')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->login('owner-tes', 'benar-123')->assertOk();
    }

    public function test_the_username_is_case_insensitive_for_counting(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login($i % 2 ? 'OWNER-TES' : 'owner-tes', 'salah')->assertStatus(401);
        }

        $this->login('Owner-Tes', 'benar-123')->assertStatus(429);
    }

    public function test_an_inactive_account_attempt_counts_as_a_failure(): void
    {
        User::factory()->create(['username' => 'mati', 'role' => 'cashier', 'password' => Hash::make('benar-789'), 'is_active' => false]);

        for ($i = 0; $i < 5; $i++) {
            $this->login('mati', 'benar-789')->assertStatus(401);
        }

        $this->login('mati', 'benar-789')->assertStatus(429);
    }

    public function test_the_per_address_flood_cap_stops_many_usernames(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->login("u{$i}", 'x')->assertStatus(401);
        }

        $this->login('u99', 'x')->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    /** 078 (tinjauan keamanan) — tebakan yang disebar lintas IP tetap dibatasi per akun (30/mnt dari semua IP), walau tiap IP baru mencoba sekali. */
    public function test_guesses_spread_across_addresses_are_capped_per_account(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.$i"])->postJson('/api/v1/auth/login', ['username' => 'owner-tes', 'password' => 'salah'])->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.200'])->postJson('/api/v1/auth/login', ['username' => 'owner-tes', 'password' => 'salah'])
            ->assertStatus(429)->assertJsonPath('code', 'throttled');
        // Batas per akun sengaja lebih longgar dari per IP, dan tidak menyentuh akun lain.
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.201'])->postJson('/api/v1/auth/login', ['username' => 'admin-tes', 'password' => 'benar-456'])->assertOk();
    }

    /** 078 (tinjauan keamanan) — percobaan dihitung SEBELUM verifikasi password (increment atomik), jadi tak ada celah "cek lalu hit" untuk permintaan paralel. */
    public function test_the_attempt_is_counted_before_the_password_is_verified(): void
    {
        $this->login('owner-tes', 'salah')->assertStatus(401);

        $this->assertSame(1, \Illuminate\Support\Facades\RateLimiter::attempts('login|owner-tes|127.0.0.1'));
        $this->assertSame(1, \Illuminate\Support\Facades\RateLimiter::attempts('login-user|owner-tes'));
    }
}
