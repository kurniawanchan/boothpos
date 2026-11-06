<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MenuActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 057 (D4) — JAMINAN tak ada rute yang terlupa: setiap rute mutasi di api/v1 wajib membawa TEPAT SATU middleware
 * `ability`, atau terdaftar di daftar pengecualian berikut (dengan alasan). Rute baru tanpa gerbang menggagalkan tes ini.
 * Tabel EXPECTED mengunci pemetaan rute → (menu, aksi) supaya perubahan pemetaan selalu terlihat saat review.
 */
class MenuActionsRouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** "METODE uri" => alasan. Hanya rute yang memang tak berada di bawah satu menu. */
    private const EXEMPT = [
        'POST api/v1/license/activate' => 'aktivasi lisensi sebelum login',
        'POST api/v1/auth/login' => 'login',
        'POST api/v1/auth/logout' => 'milik sendiri',
        'PUT api/v1/auth/language' => 'milik sendiri (profil)',
        'PUT api/v1/auth/password' => 'milik sendiri (profil)',
        'POST api/v1/auth/photo' => 'milik sendiri (profil)',
        'POST api/v1/payment-proofs' => 'unggah berkas bukti: pembayarannya sendiri digerbang per menu pada langkah berikutnya',
        'POST api/v1/sessions/{session}/reopen' => '063: aturan TETAP khusus peran Owner, diperiksa di CashierSessionController — sengaja bukan aksi yang bisa didelegasikan lewat editor peran',
        'POST api/v1/imports/master-data' => 'berkas satu untuk banyak entitas: aksi `import` diperiksa per menu di MasterDataImportController (MenuActions::userMayImportMasterData)',
    ];

    /** Pemetaan yang dikunci: "METODE uri" => "menu.aksi". Hanya rute ber-parameter-bebas diuji perilakunya di bawah. */
    private function gatedRoutes(): array
    {
        $out = [];
        /** @var LaravelRoute $route */
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $abilities = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'ability:')));
                $out["{$method} {$route->uri()}"] = ['route' => $route, 'method' => $method, 'abilities' => $abilities];
            }
        }

        return $out;
    }

    public function test_every_mutating_route_has_exactly_one_ability_or_is_exempt(): void
    {
        $missing = [];
        $multiple = [];
        foreach ($this->gatedRoutes() as $key => $info) {
            if (in_array($info['method'], ['GET'], true)) {
                continue;
            }
            if (isset(self::EXEMPT[$key])) {
                $this->assertCount(0, $info['abilities'], "{$key} dikecualikan tetapi punya gerbang");

                continue;
            }
            if (count($info['abilities']) === 0) {
                $missing[] = $key;
            }
            if (count($info['abilities']) > 1) {
                $multiple[] = $key;
            }
        }

        $this->assertSame([], $missing, "Rute mutasi tanpa middleware `ability`:\n".implode("\n", $missing));
        $this->assertSame([], $multiple, 'Rute dengan lebih dari satu `ability`');
    }

    public function test_every_declared_pair_exists_in_the_registry(): void
    {
        foreach ($this->gatedRoutes() as $key => $info) {
            foreach ($info['abilities'] as $mw) {
                [$menu, $action] = array_pad(explode(',', substr($mw, strlen('ability:')), 2), 2, null);
                $action ??= MenuActions::fromHttpMethod($info['method']);
                $this->assertTrue(MenuActions::isValid($menu, $action), "{$key}: ability {$mw} tidak ada di MenuActions (aksi efektif {$action})");
            }
        }
    }

    public function test_extraction_routes_are_gated_with_an_export_style_action(): void
    {
        foreach (['GET api/v1/customers/export', 'GET api/v1/invoices/export', 'GET api/v1/preorders/export', 'GET api/v1/catalog/export', 'GET api/v1/reports/{report}/export', 'GET api/v1/backups/{id}/download'] as $key) {
            $info = $this->gatedRoutes()[$key] ?? null;
            $this->assertNotNull($info, "{$key} tidak ada");
            $this->assertCount(1, $info['abilities'], "{$key} wajib punya gerbang aksi");
        }
    }

    public function test_the_mapping_is_pinned_for_the_important_routes(): void
    {
        $expected = [
            'POST api/v1/orders/{order}/void' => 'ability:sales,void',
            'POST api/v1/orders' => 'ability:pos,create',
            'POST api/v1/stock/adjustments' => 'ability:stock,adjust',
            'POST api/v1/preorders/{preorder}/split' => 'ability:preorders,split',
            'POST api/v1/settlements/{settlement}/finalize' => 'ability:settlements,finalize',
            'POST api/v1/backups/restore-upload' => 'ability:settings,restore',
            'DELETE api/v1/products/{product}' => 'ability:products',
            'POST api/v1/customers' => 'ability:customers',
            'PUT api/v1/customers/{customer}' => 'ability:customers',
            'POST api/v1/variants/{variant}/bom/complete' => 'ability:products,bom',
            'POST api/v1/sessions/{session}/close' => 'ability:session,edit',
        ];
        $all = $this->gatedRoutes();
        foreach ($expected as $key => $mw) {
            $this->assertSame([$mw], $all[$key]['abilities'] ?? null, $key);
        }
    }

    /** Gerbang bekerja pada rute NYATA: peran "hanya lihat" ditolak 403 `action_not_allowed` pada tiap rute mutasi tanpa parameter. */
    public function test_a_view_only_role_is_refused_on_every_parameterless_gated_route(): void
    {
        $checked = 0;
        foreach ($this->gatedRoutes() as $key => $info) {
            if ($info['abilities'] === [] || str_contains($info['route']->uri(), '{') || $info['method'] === 'GET') {
                continue;
            }
            $menu = explode(',', substr($info['abilities'][0], strlen('ability:')))[0];
            $u = User::factory()->create();
            $u->update(['role_id' => Role::factory()->create(['menu_keys' => [$menu], 'menu_actions' => [$menu => []]])->id]);
            $this->actingAs($u->fresh(), 'sanctum');

            $this->json($info['method'], '/'.$info['route']->uri(), [])
                ->assertStatus(403)
                ->assertJsonPath('code', 'action_not_allowed');
            $checked++;
        }

        $this->assertGreaterThan(10, $checked);
    }
}
