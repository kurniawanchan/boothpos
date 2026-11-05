<?php

namespace Tests\Feature\SellerScope;

use App\Models\Artist;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Concerns\SellerScope;
use App\Support\SellerGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerGateTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    public function test_gate_is_null_without_user_and_for_user_without_seller(): void
    {
        $this->assertNull(SellerGate::current());
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->assertNull(SellerGate::current());
        $this->assertFalse(SellerGate::active());
    }

    public function test_gate_returns_the_seller_id_of_a_scoped_user(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a']);
        $this->assertSame($w['a']->id, SellerGate::current());
    }

    public function test_models_are_narrowed_for_a_scoped_user_and_unchanged_without(): void
    {
        $w = $this->buildSellerWorld();

        // Tanpa seller: semuanya terlihat (perilaku lama).
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->assertSame(2, Product::count());
        $this->assertSame(3, Order::count());

        // Dengan seller A.
        $this->actingAsSellerUser($w['a']);
        $this->assertEquals([$w['productA']->id], Product::pluck('id')->all());
        $this->assertEquals([$w['a']->id], Artist::pluck('id')->all());
        // Header terlihat bila ADA baris A: hanya-A dan campuran, bukan hanya-B (Q1:B).
        $this->assertEqualsCanonicalizing([$w['orderA']->id, $w['orderMixed']->id], Order::pluck('id')->all());
        // Order campuran tampil UTUH (baris seller B ikut terlihat).
        $this->assertCount(2, Order::findOrFail($w['orderMixed']->id)->items);
        // Riwayat stok hanya varian milik A.
        $this->assertSame([$w['variantA']->id], StockMovement::distinct()->pluck('variant_id')->all());
        // Pelanggan: yang terhubung ke order terlihat (A dan campuran -> keduanya), bukan yang tak terhubung.
        $this->assertEqualsCanonicalizing([$w['customerA']->id, $w['customerB']->id], Customer::pluck('id')->all());
    }

    public function test_the_scope_can_be_released_explicitly_for_existence_checks(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a']);
        $this->assertSame(2, Product::withoutGlobalScope(SellerScope::class)->count());
    }

    public function test_deactivated_seller_still_scopes_instead_of_falling_back_to_everything(): void
    {
        $w = $this->buildSellerWorld();
        $user = $this->actingAsSellerUser($w['a']);
        $w['a']->update(['is_active' => false]);
        $this->assertSame($w['a']->id, SellerGate::current());
        $this->assertSame(1, Product::count());
        $this->assertNotNull($user->fresh()->artist);
    }
}
