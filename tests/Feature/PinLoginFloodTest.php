<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 078 (tinjauan keamanan) — login PIN punya penahan banjir DUA lapis: per orang+outlet+IP (20/mnt) dan total per IP (30/mnt). Tanpa lapis kedua,
 * satu IP bisa mencoba banyak `user_id` bergantian tanpa batas (kunci per orang tidak pernah terisi). Rem sebenarnya tetap kunci 5-salah per pengguna.
 */
class PinLoginFloodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function attempt(int $userId, string $ip = '10.2.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/pin-sessions', [
            'outlet_id' => 1, 'user_id' => $userId, 'pin' => '482916', 'device_id' => 1,
        ]);
    }

    public function test_one_address_cannot_try_many_users_without_a_total_cap(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->assertNotSame(429, $this->attempt(1000 + $i)->status(), "percobaan $i tidak boleh terkena throttle");
        }

        $this->attempt(2000)->assertStatus(429)->assertJsonPath('code', 'throttled');
    }

    public function test_another_address_is_not_affected_by_the_total_cap(): void
    {
        for ($i = 1; $i <= 31; $i++) {
            $this->attempt(1000 + $i);
        }

        $this->assertNotSame(429, $this->attempt(5000, '10.2.0.2')->status());
    }

    public function test_the_per_person_cap_still_applies(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->assertNotSame(429, $this->attempt(7)->status());
        }

        $this->attempt(7)->assertStatus(429);
    }
}
