<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 048 / T066 — staf yang dinonaktifkan di tengah sesi tidak boleh menulis lagi dengan token yang sudah ada.
 */
class InactiveUserTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deactivated_user_loses_access_with_an_existing_token_and_the_token_is_revoked(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $token = $user->createToken('terminal')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/sessions/current')->assertStatus(404); // aktif: sampai ke pengendali

        $user->forceFill(['is_active' => false])->save();
        // Setiap permintaan nyata berjalan di proses baru; di tes, guard harus dilupakan agar user dibaca ulang dari DB.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/sessions/current')
            ->assertStatus(401)
            ->assertJsonPath('code', 'account_inactive');

        $this->assertSame(0, $user->tokens()->count(), 'token akun nonaktif harus dicabut');
    }

    public function test_a_reactivated_user_cannot_use_the_old_token(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $token = $user->createToken('terminal')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();
        $this->withToken($token)->getJson('/api/v1/sessions/current')->assertStatus(401);

        $user->forceFill(['is_active' => true])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/sessions/current')->assertStatus(401);
    }
}
