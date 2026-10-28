<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 079 / US4 — `GET /settings/features` membawa identitas toko untuk outlet yang berlaku pada permintaan ini
 * (X-Outlet-Id, atau satu-satunya outlet yang boleh dilihat); tanpa pilihan di antara beberapa outlet = nilai bersama.
 */
class FeaturesStoreIdentityTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private Outlet $a;

    private Outlet $b;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['store_name' => 'Toko Bersama', 'store_address' => 'Jl. Bersama 1', 'store_contact_phone' => '021-222', 'store_contact_email' => 'bersama@example.test'] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->a->update(['name' => 'Outlet A', 'address' => 'Jl. A 10', 'contact_phone' => '0811-A', 'contact_email' => 'a@example.test', 'notes' => 'Catatan A']);
        $this->b->update(['name' => 'Outlet B', 'address' => 'Jl. B 20', 'contact_phone' => null, 'contact_email' => 'b@example.test', 'notes' => null]);
    }

    private function features(array $headers = [])
    {
        return $this->getJson('/api/v1/settings/features', $headers)->assertOk();
    }

    public function test_the_selected_outlet_drives_the_store_block_and_store_name(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $a = $this->features(['X-Outlet-Id' => $this->a->id]);
        $b = $this->features(['X-Outlet-Id' => $this->b->id]);

        $this->assertSame('Outlet A', $a->json('store.name'));
        $this->assertSame('Outlet A', $a->json('store_name'));
        $this->assertSame('Jl. A 10', $a->json('store.address'));
        $this->assertSame('0811-A', $a->json('store.phone'));
        $this->assertSame('Catatan A', $a->json('store.notes'));

        $this->assertSame('Outlet B', $b->json('store.name'));
        $this->assertSame('Outlet B', $b->json('store_name'));
        $this->assertSame('Jl. B 20', $b->json('store.address'));
    }

    public function test_an_empty_outlet_field_falls_back_to_the_shared_value_on_screens(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $b = $this->features(['X-Outlet-Id' => $this->b->id]);

        $this->assertSame('021-222', $b->json('store.phone'), 'B tidak punya telepon → nilai bersama pada layar');
        $this->assertNull($b->json('store.notes'));
        $this->assertSame('b@example.test', $b->json('store.email'));
    }

    public function test_all_outlets_with_several_permitted_shows_the_shared_identity(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $r = $this->features();

        $this->assertSame('Toko Bersama', $r->json('store.name'));
        $this->assertSame('Toko Bersama', $r->json('store_name'));
        $this->assertSame('Jl. Bersama 1', $r->json('store.address'));
        $this->assertSame('021-222', $r->json('store.phone'));
    }

    public function test_a_user_with_a_single_permitted_outlet_sees_that_outlet_without_any_header(): void
    {
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->b]);
        $this->actingAs($cashier, 'sanctum');

        $this->assertSame('Outlet B', $this->features()->json('store.name'));
    }

    public function test_requests_with_different_outlets_never_leak_into_each_other(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $first = $this->features(['X-Outlet-Id' => $this->a->id])->json('store.name');
        $second = $this->features(['X-Outlet-Id' => $this->b->id])->json('store.name');
        $third = $this->features(['X-Outlet-Id' => $this->a->id])->json('store.name');

        $this->assertSame(['Outlet A', 'Outlet B', 'Outlet A'], [$first, $second, $third]);
    }

    public function test_an_outlet_the_user_is_not_assigned_to_is_still_refused(): void
    {
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->a]);
        $this->actingAs($cashier, 'sanctum');

        $this->getJson('/api/v1/settings/features', ['X-Outlet-Id' => $this->b->id])->assertForbidden();
    }
}
