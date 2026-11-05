<?php

namespace Tests\Feature\SellerScope;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 058 — seller dinonaktifkan/dihapus, diganti saat sesi berjalan, dan payload /auth/me. */
class SellerLifecycleTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    public function test_me_reports_the_seller_scope(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('seller_scoped', true)->assertJsonPath('seller_active', true)
            ->assertJsonPath('artist.id', $w['a']->id);
    }

    public function test_deactivated_seller_gives_an_empty_view_and_a_notice_flag_never_everything(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');
        $w['a']->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('seller_active', false)->assertJsonPath('seller_scoped', true);
        // Seller nonaktif tetap membatasi (produknya tetap miliknya); yang penting tidak membuka seller lain.
        $ids = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($w['productB']->id, $ids);
    }

    public function test_a_soft_deleted_seller_gives_an_empty_view_without_error(): void
    {
        $w = $this->buildSellerWorld();
        $user = $this->actingAsSellerUser($w['a'], 'admin');
        Product::where('artist_id', $w['a']->id)->delete();
        $w['a']->delete();

        $this->assertNotNull(User::find($user->id)->artist_id);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('seller_scoped', true);
        $this->assertSame([], $this->getJson('/api/v1/products')->assertOk()->json('data'));
        // Riwayat stok produk yang sudah dihapus lunak SENGAJA tetap terlihat (research D4), tetapi hanya milik seller ini.
        $variantIds = collect($this->getJson('/api/v1/stock/movements?per_page=100')->assertOk()->json('data'))->pluck('variant_id')->unique()->all();
        $this->assertSame([$w['variantA']->id], array_values($variantIds));
    }

    public function test_changing_the_seller_applies_on_the_next_request(): void
    {
        $w = $this->buildSellerWorld();
        $user = $this->actingAsSellerUser($w['a'], 'admin');
        $this->assertSame([$w['productA']->id], collect($this->getJson('/api/v1/products')->json('data'))->pluck('id')->all());

        User::whereKey($user->id)->update(['artist_id' => $w['b']->id]);
        $this->actingAs(User::find($user->id), 'sanctum'); // permintaan berikutnya memuat pengguna dari database

        $this->assertSame([$w['productB']->id], collect($this->getJson('/api/v1/products')->json('data'))->pluck('id')->all());
    }

    public function test_the_owner_is_never_scoped_and_me_says_so(): void
    {
        $w = $this->buildSellerWorld();
        $owner = User::factory()->create(['role' => 'owner']);
        $owner->forceFill(['artist_id' => $w['a']->id])->save(); // ikatan basi
        $this->actingAs($owner->fresh(), 'sanctum');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('seller_scoped', false)->assertJsonPath('artist', null);
        $this->assertCount(2, $this->getJson('/api/v1/products')->json('data'));
    }
}
