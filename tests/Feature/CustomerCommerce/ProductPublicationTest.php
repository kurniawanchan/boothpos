<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\MenuActions;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §6) — kendali terbit staf:
 * POST /products/{product}/publish|unpublish dan POST /variants/{variant}/publish|unpublish.
 *
 * Terbit adalah aksi BERNAMA `publish` di menu Produk, sengaja terpisah dari
 * Ubah: SPA mengirim seluruh produk saat mengedit, jadi kalau `published_at`
 * ikut di PUT /products, peran yang hanya boleh mengubah bisa membuka barang
 * ke publik. Tiap perubahan dicatat di activity_logs dalam transaksi yang sama.
 */
class ProductPublicationTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private function actingAsStaff(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** Peran rakitan sendiri: menu dan izin aksi persis seperti yang diberikan. */
    private function actingAsRole(array $menuKeys, ?array $menuActions): User
    {
        $user = User::factory()->create();
        $user->update(['role_id' => Role::factory()->create(['menu_keys' => $menuKeys, 'menu_actions' => $menuActions])->id]);
        $this->actingAs($user->fresh(), 'sanctum');

        return $user;
    }

    /**
     * Produk BELUM terbit dengan dua varian aktif berstok dan satu varian nonaktif, semuanya belum terbit.
     *
     * @return array{product: Product, a: ProductVariant, b: ProductVariant, inactive: ProductVariant}
     */
    private function draftProduct(array $attrs = []): array
    {
        $product = Product::factory()->create($attrs + ['name' => 'Poster Draf']);
        $a = $this->addStorefrontVariant($product, 'A3', 20000, 5, published: false);
        $b = $this->addStorefrontVariant($product, 'A4', 15000, 5, published: false);
        $inactive = $this->addStorefrontVariant($product, 'Lama', 9000, 5, published: false);
        $inactive->update(['is_active' => false]);

        return ['product' => $product->fresh(), 'a' => $a, 'b' => $b, 'inactive' => $inactive->fresh()];
    }

    /** @return list<string> */
    private function storefrontNames(): array
    {
        return $this->getJson('/api/v1/storefront/products')->assertOk()->json('data.*.name');
    }

    // ------------------------------------------------------------------
    // Registri izin
    // ------------------------------------------------------------------

    public function test_publish_is_a_named_action_of_the_products_menu_with_labels_in_both_languages(): void
    {
        $this->assertContains('publish', MenuActions::for('products'));
        $this->assertFalse(MenuActions::isStandard('publish'));

        foreach (['id', 'en'] as $locale) {
            $label = __('permissions.actions.publish', [], $locale);
            $this->assertNotSame('permissions.actions.publish', $label, $locale);
            $this->assertNotSame('', trim($label), $locale);
        }
    }

    // ------------------------------------------------------------------
    // Terbit / tarik produk
    // ------------------------------------------------------------------

    public function test_publishing_a_product_stamps_it_and_leaves_its_variants_alone_by_default(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $draft = $this->draftProduct();
        $staff = $this->actingAsStaff();

        $response = $this->postJson("/api/v1/products/{$draft['product']->id}/publish")->assertOk();

        $response->assertJsonPath('id', $draft['product']->id)
            ->assertJsonPath('slug', 'poster-draf')
            ->assertJsonPath('published_at', now()->toIso8601String());
        $this->assertSame([null, null, null], $response->json('variants.*.published_at'));

        $this->assertTrue($draft['product']->fresh()->published_at->equalTo(now()));
        $this->assertNull($draft['a']->fresh()->published_at);

        $log = ActivityLog::where('action', 'storefront_published')->sole();
        $this->assertSame('Product', $log->entity_type);
        $this->assertSame($draft['product']->id, $log->entity_id);
        $this->assertSame($staff->id, $log->user_id);
        $this->assertNull($log->old_values['published_at']);
        $this->assertSame(now()->toIso8601String(), $log->new_values['published_at']);
        $this->assertSame([], $log->new_values['published_variant_ids']);
    }

    public function test_with_variants_also_publishes_the_unpublished_active_variants_only(): void
    {
        $draft = $this->draftProduct();
        $earlier = now()->subDays(3)->startOfSecond();
        $this->publishForStorefront($draft['b'], $earlier); // sudah terbit lebih dulu: tanggalnya tidak boleh berubah
        $this->actingAsStaff();

        $response = $this->postJson("/api/v1/products/{$draft['product']->id}/publish", ['with_variants' => true])->assertOk();

        $this->assertNotNull($draft['a']->fresh()->published_at);
        $this->assertTrue($draft['b']->fresh()->published_at->equalTo($earlier));
        $this->assertNull($draft['inactive']->fresh()->published_at, 'Varian nonaktif tidak ikut diterbitkan.');

        $published = collect($response->json('variants'))->pluck('published_at', 'id');
        $this->assertNotNull($published[$draft['a']->id]);
        $this->assertNull($published[$draft['inactive']->id]);

        $log = ActivityLog::where('action', 'storefront_published')->sole();
        $this->assertSame([$draft['a']->id], $log->new_values['published_variant_ids']);
    }

    public function test_with_variants_must_be_a_boolean(): void
    {
        $draft = $this->draftProduct();
        $this->actingAsStaff();

        $this->postJson("/api/v1/products/{$draft['product']->id}/publish", ['with_variants' => 'semua'])
            ->assertStatus(422)->assertJsonValidationErrors(['with_variants']);

        $this->assertNull($draft['product']->fresh()->published_at);
        $this->assertSame(0, ActivityLog::where('action', 'storefront_published')->count());
    }

    public function test_publishing_twice_keeps_the_first_date_and_logs_once(): void
    {
        $draft = $this->draftProduct();
        $this->actingAsStaff();

        Carbon::setTestNow('2026-11-12 10:00:00');
        $first = $this->postJson("/api/v1/products/{$draft['product']->id}/publish")->assertOk()->json('published_at');

        Carbon::setTestNow('2026-11-13 08:30:00');
        $this->postJson("/api/v1/products/{$draft['product']->id}/publish")->assertOk()->assertJsonPath('published_at', $first);

        $this->assertSame(1, ActivityLog::where('action', 'storefront_published')->count());
    }

    public function test_publishing_a_product_scheduled_for_later_makes_it_live_now(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $draft = $this->draftProduct();
        $this->publishForStorefront($draft['product'], now()->addWeek());
        $this->actingAsStaff();

        $this->postJson("/api/v1/products/{$draft['product']->id}/publish")->assertOk()
            ->assertJsonPath('published_at', now()->toIso8601String());
    }

    public function test_unpublishing_a_product_clears_its_date_but_keeps_the_variant_dates(): void
    {
        [$product, $variant] = $this->makeStorefrontProduct(['name' => 'Sudah Terbit']);
        $variantDate = $variant->published_at;
        $staff = $this->actingAsStaff();

        $this->postJson("/api/v1/products/{$product->id}/unpublish")->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('published_at', null);

        $this->assertNull($product->fresh()->published_at);
        // Tanggal varian disimpan supaya menerbitkan produknya lagi memulihkan susunan yang sama.
        $this->assertTrue($variant->fresh()->published_at->equalTo($variantDate));

        $log = ActivityLog::where('action', 'storefront_unpublished')->sole();
        $this->assertSame(['Product', $product->id, $staff->id], [$log->entity_type, $log->entity_id, $log->user_id]);
        $this->assertNotNull($log->old_values['published_at']);
        $this->assertNull($log->new_values['published_at']);

        // Menarik produk yang sudah ditarik: tetap 200, tanpa catatan kedua.
        $this->postJson("/api/v1/products/{$product->id}/unpublish")->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'storefront_unpublished')->count());
    }

    // ------------------------------------------------------------------
    // Terbit / tarik varian
    // ------------------------------------------------------------------

    public function test_publishing_and_unpublishing_a_variant(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $draft = $this->draftProduct();
        $staff = $this->actingAsStaff();

        $this->postJson("/api/v1/variants/{$draft['a']->id}/publish")->assertOk()
            ->assertJsonPath('id', $draft['a']->id)
            ->assertJsonPath('product_id', $draft['product']->id)
            ->assertJsonPath('published_at', now()->toIso8601String());

        $this->assertNull($draft['product']->fresh()->published_at, 'Menerbitkan varian tidak menerbitkan produknya.');
        $this->assertNull($draft['b']->fresh()->published_at);

        $log = ActivityLog::where('action', 'storefront_published')->sole();
        $this->assertSame(['ProductVariant', $draft['a']->id, $staff->id], [$log->entity_type, $log->entity_id, $log->user_id]);

        $this->postJson("/api/v1/variants/{$draft['a']->id}/unpublish")->assertOk()->assertJsonPath('published_at', null);
        $this->assertNull($draft['a']->fresh()->published_at);

        $log = ActivityLog::where('action', 'storefront_unpublished')->sole();
        $this->assertSame(['ProductVariant', $draft['a']->id], [$log->entity_type, $log->entity_id]);
    }

    // ------------------------------------------------------------------
    // Pengaruh ke etalase
    // ------------------------------------------------------------------

    public function test_publish_shows_and_unpublish_hides_immediately_on_the_storefront(): void
    {
        $this->enableCustomerCommerce();
        $draft = $this->draftProduct();
        $this->actingAsStaff();
        $id = $draft['product']->id;

        $this->assertSame([], $this->storefrontNames());

        // Produk terbit tetapi belum ada varian terbit: belum tampil.
        $this->postJson("/api/v1/products/{$id}/publish")->assertOk();
        $this->assertSame([], $this->storefrontNames());

        $this->postJson("/api/v1/variants/{$draft['a']->id}/publish")->assertOk();
        $this->assertSame(['Poster Draf'], $this->storefrontNames());
        $this->getJson('/api/v1/storefront/products/poster-draf')->assertOk()
            ->assertJsonPath('data.variants.*.id', [$draft['a']->id])
            ->assertJsonPath('data.price_max', '20000.00');

        $this->postJson("/api/v1/variants/{$draft['b']->id}/publish")->assertOk();
        $this->getJson('/api/v1/storefront/products/poster-draf')->assertOk()
            ->assertJsonPath('data.variants.*.id', [$draft['a']->id, $draft['b']->id])
            ->assertJsonPath('data.price_min', '15000.00');

        // Tarik satu varian: hilang seketika dari detail dan dari rentang harga.
        $this->postJson("/api/v1/variants/{$draft['a']->id}/unpublish")->assertOk();
        $this->getJson('/api/v1/storefront/products/poster-draf')->assertOk()
            ->assertJsonPath('data.variants.*.id', [$draft['b']->id])
            ->assertJsonPath('data.price_max', '15000.00');

        // Tarik produknya: hilang seketika dari daftar, detail dan kategori.
        $this->postJson("/api/v1/products/{$id}/unpublish")->assertOk();
        $this->assertSame([], $this->storefrontNames());
        $this->getJson('/api/v1/storefront/products/poster-draf')->assertNotFound();
        $this->getJson('/api/v1/storefront/categories')->assertOk()->assertJsonPath('data', []);

        // Terbitkan lagi: varian yang tadi masih terbit (A4) langsung kembali.
        $this->postJson("/api/v1/products/{$id}/publish")->assertOk();
        $this->getJson('/api/v1/storefront/products/poster-draf')->assertOk()
            ->assertJsonPath('data.variants.*.id', [$draft['b']->id]);
    }

    public function test_publication_works_while_the_storefront_is_switched_off(): void
    {
        $this->disableCustomerCommerce();
        $draft = $this->draftProduct();
        $this->actingAsStaff();

        // Staf menyiapkan katalog SEBELUM saklar dinyalakan.
        $this->postJson("/api/v1/products/{$draft['product']->id}/publish", ['with_variants' => true])->assertOk();
        $this->assertNotNull($draft['product']->fresh()->published_at);
    }

    // ------------------------------------------------------------------
    // Izin
    // ------------------------------------------------------------------

    /** @return array<string, string> label => uri */
    private function publicationRoutes(array $draft): array
    {
        return [
            'publish product' => "/api/v1/products/{$draft['product']->id}/publish",
            'unpublish product' => "/api/v1/products/{$draft['product']->id}/unpublish",
            'publish variant' => "/api/v1/variants/{$draft['a']->id}/publish",
            'unpublish variant' => "/api/v1/variants/{$draft['a']->id}/unpublish",
        ];
    }

    public function test_a_role_with_products_but_without_the_publish_action_is_refused_everywhere(): void
    {
        $draft = $this->draftProduct();
        // Boleh menambah/mengubah/menghapus produk, tetapi TIDAK menerbitkan.
        $this->actingAsRole(['products'], ['products' => ['create', 'edit', 'delete', 'bom', 'catalog']]);

        foreach ($this->publicationRoutes($draft) as $label => $uri) {
            $this->postJson($uri, ['with_variants' => true])
                ->assertStatus(403)
                ->assertJsonPath('code', 'action_not_allowed')
                ->assertJsonPath('menu', 'products')
                ->assertJsonPath('action', 'publish');
        }

        $this->assertNull($draft['product']->fresh()->published_at);
        $this->assertNull($draft['a']->fresh()->published_at);
        $this->assertSame(0, ActivityLog::whereIn('action', ['storefront_published', 'storefront_unpublished'])->count());
    }

    public function test_a_view_only_products_role_and_a_role_without_the_menu_are_refused(): void
    {
        $draft = $this->draftProduct();

        foreach ([[['products'], ['products' => []]], [['dashboard', 'pos'], null]] as [$menus, $actions]) {
            $this->actingAsRole($menus, $actions);

            foreach ($this->publicationRoutes($draft) as $uri) {
                $this->postJson($uri)->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
            }
        }

        $this->assertNull($draft['product']->fresh()->published_at);
    }

    public function test_a_role_without_custom_actions_and_a_role_granted_publish_may_publish(): void
    {
        // NULL menu_actions = akses penuh (aturan 057): aksi baru otomatis dimiliki peran lama.
        $draft = $this->draftProduct();
        $this->actingAsRole(['products'], null);
        foreach ($this->publicationRoutes($draft) as $label => $uri) {
            $this->postJson($uri)->assertOk();
        }

        // Peran yang HANYA diberi `publish` (tanpa Ubah) juga boleh.
        $second = $this->draftProduct(['name' => 'Poster Kedua']);
        $this->actingAsRole(['products'], ['products' => ['publish']]);
        $this->postJson("/api/v1/products/{$second['product']->id}/publish")->assertOk();
        $this->assertNotNull($second['product']->fresh()->published_at);
    }

    public function test_editing_a_product_or_variant_never_changes_publication(): void
    {
        $draft = $this->draftProduct();
        // Peran "ubah saja": tidak punya aksi publish.
        $this->actingAsRole(['products'], ['products' => ['edit']]);

        $this->putJson("/api/v1/products/{$draft['product']->id}", [
            'name' => 'Poster Draf Baru', 'published_at' => now()->toIso8601String(), 'slug' => 'slug-pilihan-klien',
        ])->assertOk()->assertJsonPath('published_at', null)->assertJsonPath('slug', 'poster-draf');

        $this->putJson("/api/v1/variants/{$draft['a']->id}", [
            'variant_name' => 'A3 Baru', 'published_at' => now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('published_at', null);

        $this->assertNull($draft['product']->fresh()->published_at);
        $this->assertSame('poster-draf', $draft['product']->fresh()->slug);
        $this->assertNull($draft['a']->fresh()->published_at);
    }

    public function test_a_seller_scoped_user_cannot_publish_another_sellers_product(): void
    {
        $sellerA = Artist::factory()->create();
        $sellerB = Artist::factory()->create();
        $own = $this->draftProduct(['name' => 'Milik A', 'artist_id' => $sellerA->id]);
        $foreign = $this->draftProduct(['name' => 'Milik B', 'artist_id' => $sellerB->id]);

        $this->actingAs(User::factory()->create(['role' => 'admin', 'artist_id' => $sellerA->id]), 'sanctum');

        foreach ($this->publicationRoutes($foreign) as $uri) {
            $this->postJson($uri, ['with_variants' => true])->assertNotFound();
        }

        $this->postJson("/api/v1/products/{$own['product']->id}/publish", ['with_variants' => true])->assertOk();
        $this->postJson("/api/v1/variants/{$own['a']->id}/unpublish")->assertOk();

        // Dicek tanpa pengguna ber-seller supaya produk seller B terlihat oleh query pemeriksa.
        $this->actingAsStaff();
        $this->assertNull($foreign['product']->fresh()->published_at);
        $this->assertNull($foreign['a']->fresh()->published_at);
        $this->assertNotNull($own['product']->fresh()->published_at);
        $this->assertNotNull($own['b']->fresh()->published_at);
        $this->assertNull($own['a']->fresh()->published_at);
    }

    public function test_a_product_of_the_other_data_mode_is_not_found(): void
    {
        $demo = ModeGate::runAs('demo', fn () => $this->draftProduct());
        $this->actingAsStaff();

        $this->postJson("/api/v1/products/{$demo['product']->id}/publish")->assertNotFound();
        $this->postJson("/api/v1/variants/{$demo['a']->id}/publish")->assertNotFound();

        $this->assertNull(ModeGate::runAs('demo', fn () => $demo['product']->fresh()->published_at));
    }

    public function test_a_customer_token_and_an_anonymous_caller_cannot_publish(): void
    {
        $draft = $this->draftProduct();
        [, $customerToken] = $this->customerWithToken();

        foreach ($this->publicationRoutes($draft) as $uri) {
            $this->withoutBearer()->postJson($uri)->assertUnauthorized();
            $this->bearer($customerToken)->postJson($uri)->assertUnauthorized();
        }

        $this->assertNull($draft['product']->fresh()->published_at);
        $this->assertSame(0, ActivityLog::whereIn('action', ['storefront_published', 'storefront_unpublished'])->count());
    }

    // ------------------------------------------------------------------
    // Audit dan resource staf
    // ------------------------------------------------------------------

    public function test_a_failed_audit_write_rolls_the_publication_back(): void
    {
        $draft = $this->draftProduct();
        $this->actingAsStaff();

        // Log aktivitas ditulis DI DALAM transaksi: kalau log gagal, terbitnya ikut batal.
        $this->app->bind(ActivityLogger::class, fn () => new class extends ActivityLogger
        {
            public function log(?int $userId, string $action, string $entityType, ?int $entityId, ?string $description = null, ?array $oldValues = null, ?array $newValues = null): ActivityLog
            {
                throw new \RuntimeException('log gagal');
            }
        });

        $this->postJson("/api/v1/products/{$draft['product']->id}/publish", ['with_variants' => true])->assertStatus(500);
        $this->postJson("/api/v1/variants/{$draft['a']->id}/publish")->assertStatus(500);

        $this->assertNull($draft['product']->fresh()->published_at);
        $this->assertNull($draft['a']->fresh()->published_at);
        $this->assertNull($draft['b']->fresh()->published_at);
    }

    public function test_staff_product_payloads_carry_slug_and_published_at(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $draft = $this->draftProduct();
        [$published, $publishedVariant] = $this->makeStorefrontProduct(['name' => 'Sudah Terbit']);
        $this->actingAsStaff();

        $this->getJson("/api/v1/products/{$draft['product']->id}")->assertOk()
            ->assertJsonPath('slug', 'poster-draf')
            ->assertJsonPath('published_at', null)
            ->assertJsonPath('variants.0.published_at', null);

        $this->getJson("/api/v1/products/{$published->id}")->assertOk()
            ->assertJsonPath('slug', 'sudah-terbit')
            ->assertJsonPath('published_at', $published->published_at->toIso8601String())
            ->assertJsonPath('variants.0.published_at', $publishedVariant->published_at->toIso8601String());

        $rows = collect($this->getJson('/api/v1/products?with_variants=1')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('poster-draf', $rows[$draft['product']->id]['slug']);
        $this->assertNull($rows[$draft['product']->id]['published_at']);
        $this->assertNotNull($rows[$published->id]['published_at']);
        $this->assertNotNull($rows[$published->id]['variants'][0]['published_at']);
    }
}
