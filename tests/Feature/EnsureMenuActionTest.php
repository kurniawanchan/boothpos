<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** 057 — middleware `ability`: menolak sebelum controller tersentuh; pesan/kode terjemahan. */
class EnsureMenuActionTest extends TestCase
{
    use RefreshDatabase;

    private int $hits = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $hits = &$this->hits;
        Route::middleware(['api', 'auth:sanctum', 'ability:preorders'])->group(function () use (&$hits) {
            Route::post('/__t/standard', function () use (&$hits) { $hits++; return ['ok' => true]; });
            Route::put('/__t/standard', function () use (&$hits) { $hits++; return ['ok' => true]; });
            Route::delete('/__t/standard', function () use (&$hits) { $hits++; return ['ok' => true]; });
        });
        Route::middleware(['api', 'auth:sanctum', 'ability:sales,void'])->post('/__t/named', function () use (&$hits) { $hits++; return ['ok' => true]; });
    }

    private function as(array $menuKeys, ?array $actions): User
    {
        $u = User::factory()->create();
        $u->update(['role_id' => Role::factory()->create(['menu_keys' => $menuKeys, 'menu_actions' => $actions])->id]);
        $this->actingAs($u->fresh(), 'sanctum');

        return $u;
    }

    public function test_http_method_derives_the_standard_action(): void
    {
        $this->as(['preorders'], ['preorders' => ['create']]);

        $this->postJson('/__t/standard')->assertOk();
        $this->putJson('/__t/standard')->assertStatus(403);
        $this->deleteJson('/__t/standard')->assertStatus(403);
        $this->assertSame(1, $this->hits);
    }

    public function test_a_named_action_is_checked_and_the_controller_is_not_reached_on_denial(): void
    {
        $this->as(['sales'], ['sales' => []]);

        $this->postJson('/__t/named')
            ->assertStatus(403)
            ->assertJsonPath('code', 'action_not_allowed')
            ->assertJsonPath('menu', 'sales')
            ->assertJsonPath('action', 'void');
        $this->assertSame(0, $this->hits);
    }

    public function test_the_named_grant_passes_through(): void
    {
        $this->as(['sales'], ['sales' => ['void']]);

        $this->postJson('/__t/named')->assertOk();
        $this->assertSame(1, $this->hits);
    }

    public function test_the_denial_message_is_translated(): void
    {
        $u = $this->as(['sales'], ['sales' => []]);
        $u->update(['language' => 'id']);

        $this->postJson('/__t/named')->assertStatus(403)->assertJsonPath('message', 'Peran Anda tidak berhak melakukan ini (Batalkan penjualan pada Penjualan).');
    }

    public function test_a_denial_is_not_written_to_the_activity_log(): void
    {
        $this->as(['sales'], ['sales' => []]);
        $before = \DB::table('activity_logs')->count();

        $this->postJson('/__t/named')->assertStatus(403);

        $this->assertSame($before, \DB::table('activity_logs')->count());
    }

    public function test_unauthenticated_stays_401(): void
    {
        $this->postJson('/__t/named')->assertStatus(401);
    }
}
