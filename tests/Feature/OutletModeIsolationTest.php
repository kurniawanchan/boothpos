<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 079 — outlet LIVE hanya tampil saat mode sistem LIVE, outlet DEMO hanya saat DEMO, di SEMUA halaman. Satu-satunya
 * jalur penyaringannya DataModeScope pada Outlet; tes ini mengunci tiga pintu yang dibaca layar: cakupan pengguna
 * (`/me/scope`, sumber bilah atas dan pilihan outlet formulir), daftar outlet, dan daftar terminal. Selain itu menolak
 * header `X-Outlet-Id` milik mode lain, supaya pilihan tersimpan dari mode sebelumnya tidak pernah menyempitkan data.
 */
class OutletModeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $live;

    private Outlet $demo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->live = ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'LV1', 'name' => 'Outlet Live']));
        $this->demo = ModeGate::runAs('demo', fn () => Outlet::factory()->create(['code' => 'DM1', 'name' => 'Outlet Demo']));
        ModeGate::runAs('live', fn () => Device::forceCreate(['outlet_id' => $this->live->id, 'name' => 'Tablet live', 'paired_at' => now()]));
        ModeGate::runAs('demo', fn () => Device::forceCreate(['outlet_id' => $this->demo->id, 'name' => 'Tablet demo', 'paired_at' => now()]));

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function mode(string $mode): void
    {
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => $mode, 'type' => 'string', 'group' => 'system']);
    }

    public static function modes(): array
    {
        return [
            'live shows only the live outlet' => ['live', 'LV1', 'DM1', 'Tablet live'],
            'demo shows only the demo outlet' => ['demo', 'DM1', 'LV1', 'Tablet demo'],
        ];
    }

    #[DataProvider('modes')]
    public function test_every_outlet_list_follows_the_system_mode(string $mode, string $shown, string $hidden, string $device): void
    {
        $this->mode($mode);

        $scope = collect($this->getJson('/api/v1/me/scope')->assertOk()->json('outlets'))->pluck('code')->all();
        $this->assertSame([$shown], $scope);

        $list = collect($this->getJson('/api/v1/outlets?per_page=100')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertContains($shown, $list);
        $this->assertNotContains($hidden, $list);

        $devices = collect($this->getJson('/api/v1/devices?per_page=100')->assertOk()->json('data'));
        $this->assertSame([$device], $devices->pluck('name')->all());
        $this->assertSame([$shown], $devices->pluck('outlet.code')->all());
    }

    public function test_a_stored_selection_from_the_other_mode_is_refused_not_applied(): void
    {
        $this->mode('demo');

        $this->withHeader('X-Outlet-Id', (string) $this->live->id)
            ->getJson('/api/v1/me/scope')
            ->assertForbidden()
            ->assertJsonPath('code', 'outlet_forbidden');
    }

    public function test_switching_the_mode_swaps_the_visible_outlets_without_any_other_change(): void
    {
        $this->mode('live');
        $this->assertSame(['LV1'], collect($this->getJson('/api/v1/me/scope')->json('outlets'))->pluck('code')->all());

        $this->mode('demo');
        $this->assertSame(['DM1'], collect($this->getJson('/api/v1/me/scope')->json('outlets'))->pluck('code')->all());
    }
}
