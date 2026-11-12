<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §6) — SATU definisi kelayakan tampil:
 * Product::scopeStorefrontVisible dan ProductVariant::scopeStorefrontSellable.
 *
 * Diuji langsung di tingkat model karena dua scope ini dipakai ulang oleh
 * quote dan checkout (bukan hanya oleh daftar etalase): varian yang "layak
 * jual" harus sudah memperhitungkan PRODUK-nya, bisa dikunci baris
 * (lockForUpdate), dan bisa dimuat bersama produknya tanpa query per baris.
 */
class StorefrontVisibilityScopeTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    public function test_visible_products_and_sellable_variants_of_the_shared_world(): void
    {
        $w = $this->buildStorefrontWorld();

        $this->assertSame([$w['product']->id], Product::query()->storefrontVisible()->pluck('id')->all());

        // Varian produk yang BELUM terbit tidak layak jual walau dirinya sendiri terbit dan berstok.
        $this->assertSame(
            [$w['variantA']->id, $w['variantB']->id],
            ProductVariant::query()->storefrontSellable()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_a_product_is_visible_only_with_at_least_one_sellable_variant(): void
    {
        [$product, $variant] = $this->makeStorefrontProduct(['name' => 'Satu Varian']);
        $this->assertTrue(Product::query()->storefrontVisible()->whereKey($product->id)->exists());

        $variant->forceFill(['published_at' => null])->save();

        $this->assertFalse(Product::query()->storefrontVisible()->whereKey($product->id)->exists());
        $this->assertFalse(ProductVariant::query()->storefrontSellable()->whereKey($variant->id)->exists());
    }

    public function test_a_variant_is_not_sellable_when_its_product_seller_or_category_is_not_eligible(): void
    {
        $break = [
            'produk nonaktif' => fn (Product $p) => $p->update(['is_active' => false]),
            'produk pre-order' => fn (Product $p) => $p->update(['is_preorder' => true]),
            'produk belum terbit' => fn (Product $p) => $p->forceFill(['published_at' => null])->save(),
            'produk terbit nanti' => fn (Product $p) => $p->forceFill(['published_at' => now()->addMinute()])->save(),
            'produk dihapus' => fn (Product $p) => $p->delete(),
            'seller nonaktif' => fn (Product $p) => $p->artist->update(['is_active' => false]),
            'seller dihapus' => fn (Product $p) => $p->artist->delete(),
            'kategori nonaktif' => fn (Product $p) => $p->category->update(['is_active' => false]),
            'kategori dihapus' => fn (Product $p) => $p->category->delete(),
        ];

        foreach ($break as $label => $apply) {
            [$product, $variant] = $this->makeStorefrontProduct(['name' => "Produk {$label}"]);
            $this->assertTrue(ProductVariant::query()->storefrontSellable()->whereKey($variant->id)->exists(), "{$label}: awalnya layak jual");

            $apply($product);

            $this->assertFalse(ProductVariant::query()->storefrontSellable()->whereKey($variant->id)->exists(), $label);
        }
    }

    public function test_the_scopes_stay_inside_the_active_data_mode(): void
    {
        [$live, $liveVariant] = $this->makeStorefrontProduct(['name' => 'Live']);
        [$demo, $demoVariant] = ModeGate::runAs('demo', fn () => $this->makeStorefrontProduct(['name' => 'Demo']));

        $this->assertSame([$live->id], Product::query()->storefrontVisible()->pluck('id')->all());
        $this->assertSame([$liveVariant->id], ProductVariant::query()->storefrontSellable()->pluck('id')->all());

        ModeGate::runAs('demo', function () use ($demo, $demoVariant) {
            $this->assertSame([$demo->id], Product::query()->storefrontVisible()->pluck('id')->all());
            $this->assertSame([$demoVariant->id], ProductVariant::query()->storefrontSellable()->pluck('id')->all());
        });
    }

    /** Checkout mengunci varian lewat scope ini: harus sah dipakai bersama lockForUpdate di dalam transaksi. */
    public function test_sellable_variants_can_be_locked_for_update(): void
    {
        $w = $this->buildStorefrontWorld();
        $ids = [$w['variantB']->id, $w['variantA']->id, $w['outOfStockVariant']->id, $w['unpublishedProductVariant']->id];

        DB::enableQueryLog();
        $locked = DB::transaction(fn () => ProductVariant::query()
            ->storefrontSellable()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get());
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertSame([$w['variantA']->id, $w['variantB']->id], $locked->pluck('id')->all());
        $this->assertStringContainsStringIgnoringCase('for update', $sql);
    }

    public function test_sellable_variants_load_with_their_product_in_a_constant_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $variants = ProductVariant::query()->storefrontSellable()->with('product')->orderBy('id')->get();
            // Menyentuh relasi dan batas beli tiap varian: tidak boleh memicu query tambahan.
            $variants->each(fn (ProductVariant $v) => [$v->product->name, $v->storefrontMaxQty()]);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        [$product] = $this->makeStorefrontProduct(['name' => 'Produk 1']);
        ModeGate::current(); // pemanasan cache pengaturan mode data
        $one = $count();

        for ($i = 2; $i <= 12; $i++) {
            $this->makeStorefrontProduct(['name' => "Produk {$i}"]);
            $this->addStorefrontVariant($product, "Varian {$i}", 10000, 2);
        }

        $this->assertSame(2, $one);
        $this->assertSame($one, $count());
    }

    public function test_the_per_line_purchase_limit_is_the_stock_capped_by_config(): void
    {
        [$product, $small] = $this->makeStorefrontProduct(['name' => 'Batas'], 10000, 4);
        $large = $this->addStorefrontVariant($product, 'Banyak', 10000, 60);
        $empty = $this->addStorefrontVariant($product, 'Kosong', 10000, 0);

        $this->assertSame(4, $small->storefrontMaxQty());
        $this->assertSame(20, $large->storefrontMaxQty());
        $this->assertSame(0, $empty->storefrontMaxQty());

        config(['customer_commerce.max_qty_per_line' => 2]);
        $this->assertSame(2, $small->storefrontMaxQty());
    }
}
