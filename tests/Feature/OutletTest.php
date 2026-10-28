<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutletTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // --- Positive ---------------------------------------------------

    public function test_owner_can_create_artist(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/outlets', [
            'code' => 'ryu',
            'name' => 'Ryu Studio',
        ]);

        $response->assertCreated()
            ->assertJsonPath('code', 'RYU'); // dinormalisasi uppercase

        $this->assertDatabaseHas('outlets', ['code' => 'RYU', 'name' => 'Ryu Studio']);
    }

    public function test_list_artists_supports_search_and_pagination(): void
    {
        $this->actingAsRole('cashier');
        Outlet::factory()->create(['name' => 'Sakura Works']);
        Outlet::factory()->create(['name' => 'Yuki Craft']);

        $response = $this->getJson('/api/v1/outlets?search=Sakura');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Sakura Works', $response->json('data.0.name'));
    }

    // --- Negative: validasi ------------------------------------------

    public function test_creating_artist_requires_name(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/outlets', ['code' => 'ABC']);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_code_must_be_three_to_five_letters_or_digits(): void
    {
        $this->actingAsRole('owner');

        // 079 — dulu tepat 3 huruf; kini 3–5 huruf/angka. Dua karakter tetap ditolak.
        $response = $this->postJson('/api/v1/outlets', [
            'code' => 'AB',
            'name' => 'Test Outlet',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_code_must_be_unique(): void
    {
        // BUG TEST YANG DITEMUKAN & DIPERBAIKI — tanpa mengaktifkan Master
        // di sini, artist pertama (dibuat langsung lewat factory) sudah
        // memenuhi kuota Pro (1 artist), sehingga permintaan KEDUA ditolak
        // LicenseGate lebih dulu (403) sebelum aturan unique 'code' sempat
        // dievaluasi — test ini gagal bukan karena validasi unique-nya
        // rusak, tapi karena setup-nya tidak mengisolasi dari fitur lisensi
        // yang memang sengaja dicek duluan. Diaktifkan Master di sini agar
        // test ini murni menguji validasi keunikan kode, terpisah dari
        // LicenseGateTest yang sudah menguji perilaku kuota itu sendiri.
        \App\Models\Setting::updateOrCreate(
            ['key' => 'multi_outlet_enabled'],
            ['value' => '1', 'type' => 'boolean', 'group' => 'licensing']
        );

        $this->actingAsRole('owner');
        Outlet::factory()->create(['code' => 'RYU']);

        $response = $this->postJson('/api/v1/outlets', [
            'code' => 'RYU',
            'name' => 'Outlet Lain',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    // --- Negative: otorisasi -------------------------------------------

    public function test_guest_cannot_access_artists(): void
    {
        $this->getJson('/api/v1/outlets')->assertStatus(401);
    }

    public function test_cashier_cannot_create_artist(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->postJson('/api/v1/outlets', [
            'code' => 'ABC',
            'name' => 'Test Outlet',
        ]);

        $response->assertStatus(403);
    }

    public function test_cashier_can_view_artist_list(): void
    {
        $this->actingAsRole('cashier');
        Outlet::factory()->create();

        $this->getJson('/api/v1/outlets')->assertOk();
    }

    public function test_inventory_role_can_create_artist(): void
    {
        $this->actingAsRole('inventory');

        $this->postJson('/api/v1/outlets', [
            'code' => 'ABC',
            'name' => 'Test Outlet',
        ])->assertCreated();
    }

    // --- Negative: business rule ---------------------------------------

    public function test_code_can_be_changed_on_update_but_must_follow_the_rule(): void
    {
        $this->actingAsRole('owner');
        $artist = Outlet::factory()->create(['code' => 'RYU']);

        // 079 — kode kini boleh disunting (F19.4 dibalik); nilai yang melanggar aturan tetap ditolak.
        $this->putJson("/api/v1/outlets/{$artist->id}", ['code' => 'NEW', 'name' => 'Nama Diperbarui'])
            ->assertOk()->assertJsonPath('code', 'NEW');

        $this->putJson("/api/v1/outlets/{$artist->id}", ['code' => 'N-1', 'name' => 'Nama Diperbarui'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertDatabaseHas('outlets', ['id' => $artist->id, 'code' => 'NEW']);
    }

    public function test_updating_name_without_touching_code_succeeds(): void
    {
        $this->actingAsRole('admin');
        $artist = Outlet::factory()->create(['code' => 'RYU', 'name' => 'Lama']);

        $response = $this->putJson("/api/v1/outlets/{$artist->id}", [
            'name' => 'Nama Baru',
        ]);

        $response->assertOk()->assertJsonPath('name', 'Nama Baru');
    }

    public function test_deleted_artist_is_excluded_from_default_listing(): void
    {
        $this->actingAsRole('owner');
        $artist = Outlet::factory()->create();

        $this->deleteJson("/api/v1/outlets/{$artist->id}")->assertStatus(204);

        $response = $this->getJson('/api/v1/outlets');
        $this->assertCount(0, $response->json('data'));
    }
}
