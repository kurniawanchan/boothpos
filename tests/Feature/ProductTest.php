<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function baseline(): array
    {
        return [
            'artist' => Artist::factory()->create(['code' => 'RYU']),
            'category' => Category::factory()->create(['code' => 'KY']),
        ];
    }

    public function test_owner_can_create_product_with_generated_code_prefix(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'product_segment' => 'SAK',
            'name' => 'Keychain Sakura',
            'variants' => [
                ['variant_name' => 'Varian A', 'sell_price' => 25000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('code_prefix', 'RYU-KY-SAK')
            ->assertJsonPath('variants.0.sku', 'RYU-KY-SAK-1');
    }

    public function test_segment_is_derived_from_name_when_not_provided(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'name' => 'Poster Yuki',
            'variants' => [['variant_name' => 'Standard', 'sell_price' => 15000]],
        ]);

        $response->assertCreated()->assertJsonPath('code_prefix', 'RYU-KY-POS');
    }

    public function test_second_variant_gets_sequential_sku(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $product = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'product_segment' => 'SAK', 'name' => 'Keychain',
            'variants' => [['variant_name' => 'A', 'sell_price' => 20000]],
        ])->json();

        $response = $this->postJson("/api/v1/products/{$product['id']}/variants", [
            'variant_name' => 'B', 'sell_price' => 22000,
        ]);

        $response->assertCreated()->assertJsonPath('sku', 'RYU-KY-SAK-2');
    }

    public function test_duplicate_code_prefix_is_rejected(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $payload = [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'product_segment' => 'SAK', 'name' => 'Keychain Sakura',
            'variants' => [['variant_name' => 'A', 'sell_price' => 20000]],
        ];

        $this->postJson('/api/v1/products', $payload)->assertCreated();

        $response = $this->postJson('/api/v1/products', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors('product_segment');
    }

    /** @return array<string, mixed> */
    private function productPayload(int $artistId, int $categoryId, array $overrides = []): array
    {
        return array_merge([
            'artist_id' => $artistId,
            'category_id' => $categoryId,
            'name' => 'Produk Uji',
            'variants' => [['variant_name' => 'Standard', 'sell_price' => 15000]],
        ], $overrides);
    }

    /** Spec 042 US1 (FR-001..FR-003): segmen 1–3 huruf disimpan apa adanya. */
    public function test_short_segment_is_saved_as_typed_and_builds_the_code(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'S',
        ]))->assertCreated()
            ->assertJsonPath('code_prefix', 'RYU-KY-S')
            ->assertJsonPath('variants.0.sku', 'RYU-KY-S-1');
    }

    public function test_lowercase_segment_is_stored_uppercase(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => ' sh ',
        ]))->assertCreated()
            ->assertJsonPath('code_prefix', 'RYU-KY-SH');

        $this->assertDatabaseHas('products', ['code_prefix' => 'RYU-KY-SH', 'product_segment' => 'SH']);
    }

    /** Spec 046 (T006): segmen 6 karakter diterima; kode dan SKU memakainya utuh. */
    public function test_a_six_character_segment_is_accepted_and_builds_the_code(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'ABCDEF',
        ]))->assertCreated()
            ->assertJsonPath('code_prefix', 'RYU-KY-ABCDEF')
            ->assertJsonPath('variants.0.sku', 'RYU-KY-ABCDEF-1');
    }

    /** Spec 046 FR-001: satu tanda hubung di antara huruf diterima dan disimpan utuh. */
    public function test_a_hyphenated_segment_is_saved_whole(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'sh-k',
        ]))->assertCreated()
            ->assertJsonPath('code_prefix', 'RYU-KY-SH-K')
            ->assertJsonPath('variants.0.sku', 'RYU-KY-SH-K-1');

        $this->assertDatabaseHas('products', ['code_prefix' => 'RYU-KY-SH-K', 'product_segment' => 'SH-K']);
    }

    /** Spec 046 (T024): tanda hubung di awal, di akhir, atau dua berturut-turut ditolak. */
    public function test_a_segment_with_misplaced_hyphens_is_refused(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        foreach (['-SH', 'SH-', 'S--K'] as $segment) {
            $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
                'product_segment' => $segment,
            ]))->assertStatus(422)->assertJsonValidationErrors('product_segment');
        }

        $this->assertSame(0, Product::count());
    }

    public function test_segment_longer_than_six_characters_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'ABCDEFG',
        ]))->assertStatus(422)->assertJsonValidationErrors('product_segment');

        $this->assertSame(0, Product::count());
    }

    public function test_segment_with_digits_or_symbols_is_refused(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        foreach (['S1', 'S-', 'S H'] as $segment) {
            $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
                'product_segment' => $segment,
            ]))->assertStatus(422)->assertJsonValidationErrors('product_segment');
        }

        $this->assertSame(0, Product::count());
    }

    /** Spec 042 US2 (FR-004, FR-005): SKU = kode produk + urutan 3 digit, segmen apa adanya. */
    public function test_skus_follow_a_short_segment_across_variants(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'S',
            'variants' => [
                ['variant_name' => 'A', 'sell_price' => 15000],
                ['variant_name' => 'B', 'sell_price' => 15000],
            ],
        ]))->assertCreated()
            ->assertJsonPath('variants.0.sku', 'RYU-KY-S-1')
            ->assertJsonPath('variants.1.sku', 'RYU-KY-S-2');
    }

    public function test_segment_three_letters_is_not_shortened_in_the_sku(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'SPF',
        ]))->assertCreated()
            ->assertJsonPath('code_prefix', 'RYU-KY-SPF')
            ->assertJsonPath('variants.0.sku', 'RYU-KY-SPF-1');
    }

    /** Spec 042 US3 (FR-007): segmen kosong tetap diturunkan dari nama, 3 huruf dengan 'X'. */
    public function test_blank_segment_derives_three_letters_from_the_name(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'name' => 'Conan',
        ]))->assertCreated()->assertJsonPath('code_prefix', 'RYU-KY-CON');

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'name' => 'Ai',
        ]))->assertCreated()->assertJsonPath('code_prefix', 'RYU-KY-AIX');
    }

    /** Spec 042 FR-010 + edge case: tabrakan kode dicek lintas DEMO dan LIVE. */
    public function test_short_segment_colliding_with_a_demo_product_is_refused(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        ModeGate::runAs('demo', fn () => Product::factory()->create([
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'code_prefix' => 'RYU-KY-S',
            'product_segment' => 'S',
            'name' => 'Demo Produk',
        ]));

        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'S',
        ]))->assertStatus(422)->assertJsonValidationErrors('product_segment');

        // Segmen lain yang berbeda tidak bertabrakan.
        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'SX',
        ]))->assertCreated()->assertJsonPath('code_prefix', 'RYU-KY-SX');
    }

    /** Spec 044 US1 (FR-001): nomor tanpa nol di depan, sampai dua digit dan lebih. */
    public function test_new_variants_get_plain_incrementing_numbers(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $variants = array_map(fn ($i) => ['variant_name' => "Varian {$i}", 'sell_price' => 15000], range(1, 11));

        $response = $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'S',
            'variants' => $variants,
        ]))->assertCreated();

        $response->assertJsonPath('variants.0.sku', 'RYU-KY-S-1')
            ->assertJsonPath('variants.1.sku', 'RYU-KY-S-2')
            ->assertJsonPath('variants.10.sku', 'RYU-KY-S-11');
    }

    /** Spec 044 US1 (FR-002): nomor tidak pernah dipakai ulang, termasuk setelah hapus. */
    /** Spec 045 FR-016: nomor varian yang dihapus boleh dipakai lagi (hanya varian aktif yang dihitung). */
    public function test_a_deleted_variant_number_is_free_again(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'S',
            'variants' => [
                ['variant_name' => 'A', 'sell_price' => 15000],
                ['variant_name' => 'B', 'sell_price' => 15000],
                ['variant_name' => 'C', 'sell_price' => 15000],
            ],
        ]))->assertCreated();

        $product = Product::findOrFail($response->json('id'));
        $product->variants()->where('sku', 'RYU-KY-S-2')->firstOrFail()->delete();

        // Nomor 2 kosong; yang tertinggi aktif adalah 3, jadi nomor berikutnya 4.
        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_name' => 'D', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'RYU-KY-S-4');

        // Nomor tertinggi yang dihapus juga bebas: setelah 4 dihapus, berikutnya 4 lagi.
        $product->variants()->where('sku', 'RYU-KY-S-4')->firstOrFail()->delete();
        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_name' => 'E', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'RYU-KY-S-4');
    }

    /** Spec 044 US2 (FR-003): variant lama dengan format `-001` tetap; nomor baru lanjut. */
    public function test_existing_padded_skus_stay_and_new_numbers_continue_after_them(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $product = Product::factory()->create([
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'code_prefix' => 'RYU-KY-SAK',
            'product_segment' => 'SAK',
        ]);
        $product->variants()->create(['sku' => 'RYU-KY-SAK-001', 'variant_name' => 'A', 'sell_price' => 15000, 'current_stock' => 0]);
        $product->variants()->create(['sku' => 'RYU-KY-SAK-002', 'variant_name' => 'B', 'sell_price' => 15000, 'current_stock' => 0]);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_name' => 'C', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'RYU-KY-SAK-3');

        $this->assertDatabaseHas('product_variants', ['sku' => 'RYU-KY-SAK-001']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'RYU-KY-SAK-002']);
    }

    /** Spec 044 US2: SKU lama tanpa tanda hubung tetap; nomor baru mengikuti urutannya. */
    public function test_a_legacy_undashed_sku_is_kept_and_the_next_number_follows_it(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $product = Product::factory()->create([
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'code_prefix' => 'RYUKYSAK',
            'product_segment' => 'SAK',
        ]);
        $product->variants()->create(['sku' => 'RYUKYSAK0001', 'variant_name' => 'A', 'sell_price' => 15000, 'current_stock' => 0]);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_name' => 'B', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'RYUKYSAK-2');

        $this->assertDatabaseHas('product_variants', ['sku' => 'RYUKYSAK0001']);
    }

    /** Spec 044 US2: mengubah nama produk tidak mengubah SKU apa pun. */
    public function test_an_ordinary_product_edit_leaves_every_sku_unchanged(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $created = $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'S',
            'variants' => [
                ['variant_name' => 'A', 'sell_price' => 15000],
                ['variant_name' => 'B', 'sell_price' => 15000],
            ],
        ]))->assertCreated();

        $this->putJson("/api/v1/products/{$created->json('id')}", [
            'name' => 'Nama Baru Saja',
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', ['sku' => 'RYU-KY-S-1']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'RYU-KY-S-2']);
        $this->assertDatabaseHas('products', ['code_prefix' => 'RYU-KY-S']);
    }

    /** Spec 044 US4 (FR-008): respons produk membawa segmen yang tersimpan. */
    public function test_product_response_carries_the_saved_segment(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $created = $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, [
            'product_segment' => 'S',
        ]))->assertCreated();

        $this->getJson("/api/v1/products/{$created->json('id')}")
            ->assertOk()
            ->assertJsonPath('product_segment', 'S');
    }

    /** Spec 045 US3: setelah segmen berganti, nomor baru tetap melanjutkan nomor SKU diketik. */
    public function test_the_next_number_continues_past_a_typed_sku_kept_under_an_old_code(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'code_prefix' => 'RYU-KY-S', 'product_segment' => 'S',
        ]);
        $product->variants()->create(['sku' => 'RYU-KY-S-7', 'variant_name' => 'Diketik', 'sell_price' => 15000, 'current_stock' => 0, 'sku_is_typed' => true]);
        $product->variants()->create(['sku' => 'RYU-KY-S-2', 'variant_name' => 'Dibuat', 'sell_price' => 15000, 'current_stock' => 0]);

        $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'product_segment' => 'T'])->assertOk();

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_name' => 'Baru', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'RYU-KY-T-8');
    }

    public function test_product_requires_at_least_one_variant(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'name' => 'Tanpa Varian', 'variants' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('variants');
    }

    public function test_preorder_product_requires_eta(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'name' => 'Pre-order Figure', 'is_preorder' => true,
            'variants' => [['variant_name' => 'A', 'sell_price' => 500000]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('preorder_eta');
    }

    public function test_cashier_cannot_create_product(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'name' => 'Test', 'variants' => [['variant_name' => 'A', 'sell_price' => 1000]],
        ])->assertStatus(403);
    }

    public function test_product_with_active_variant_cannot_be_deleted(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000, 'is_active' => true]);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(409);
    }

    public function test_product_list_omits_variants_by_default(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 25000, 'current_stock' => 7]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $this->assertArrayNotHasKey('variants', $response->json('data.0'));
    }

    // Menutup N+1 di layar kasir: PosView dulu mengambil detail tiap produk
    // satu per satu hanya untuk mendapatkan varian.
    public function test_product_list_includes_variants_when_asked(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 25000, 'current_stock' => 7]);
        $product->variants()->create(['sku' => 'RYUKYAAA0002', 'sell_price' => 30000, 'current_stock' => 3]);

        $response = $this->getJson('/api/v1/products?with_variants=1');

        $response->assertOk()
            ->assertJsonCount(2, 'data.0.variants')
            ->assertJsonPath('data.0.variants.0.sku', 'RYUKYAAA0001')
            ->assertJsonPath('data.0.variants.0.sell_price', '25000.00')
            ->assertJsonPath('data.0.variants.0.current_stock', 7);
    }

    // ?search matches product name OR a variant's own sku/variant_name —
    // a cashier searching for one specific SKU or variant design name (not
    // just the parent product's name) must still find the right product.
    public function test_product_list_search_matches_product_name(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Keychain Sakura']);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 25000]);
        Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Poster Yuki']);

        $response = $this->getJson('/api/v1/products?search=Sakura');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Keychain Sakura');
    }

    public function test_product_list_search_matches_variant_sku(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Keychain Sakura']);
        $product->variants()->create(['sku' => 'RYU-KY-SAK-001', 'sell_price' => 25000]);
        Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Poster Yuki']);

        $response = $this->getJson('/api/v1/products?search=SAK-001');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Keychain Sakura');
    }

    // The follow-up fix — searching by a variant's own name (e.g. a design
    // or motif) must find the product even when neither the product name
    // nor the SKU contains the search term at all.
    public function test_product_list_search_matches_variant_name(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Love Bullet']);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'variant_name' => 'Koharu', 'sell_price' => 40000]);
        Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Poster Yuki']);

        $response = $this->getJson('/api/v1/products?search=Koharu');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Love Bullet');
    }

    // Varian dimuat lewat eager-load, bukan lazy-load per baris di resource.
    public function test_product_list_with_variants_does_not_run_a_query_per_product(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        foreach (range(1, 5) as $i) {
            $product = Product::factory()->create([
                'artist_id' => $artist->id,
                'category_id' => $category->id,
                'code_prefix' => 'RYUKYP'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
            $product->variants()->create(['sku' => 'RYUKYP'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'0001', 'sell_price' => 1000]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/v1/products?with_variants=1')->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // 048: query daftar utama kini memuat subselect agregat total stok (alias total_stock*) atas product_variants;
        // itu satu query bersama untuk semua produk, jadi tidak dihitung sebagai query eager-load varian.
        $variantQueries = collect($queries)
            ->filter(fn ($q) => str_contains($q['query'], 'product_variants') && ! str_contains($q['query'], 'total_stock'))
            ->count();

        $this->assertSame(1, $variantQueries, 'Varian harus dimuat dalam satu query eager-load, bukan satu query per produk.');
    }

    public function test_variant_lookup_finds_by_sku_fragment(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Keychain Sakura']);
        $product->variants()->create(['sku' => 'RYU-KY-SAK-001', 'sell_price' => 25000, 'current_stock' => 10]);

        $response = $this->getJson('/api/v1/variants/lookup?q=SAK-001');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('RYU-KY-SAK-001', $response->json('data.0.sku'));
    }

    // Same follow-up as the products list search — a cashier at POS or a
    // pre-order search may only know a variant's own name (e.g. a design),
    // not its SKU or the parent product's name.
    public function test_variant_lookup_finds_by_variant_name(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Love Bullet']);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'variant_name' => 'Koharu', 'sell_price' => 40000, 'current_stock' => 10]);

        $response = $this->getJson('/api/v1/variants/lookup?q=Koharu');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('RYUKYAAA0001', $response->json('data.0.sku'));
    }

    // Requested for the preorder "Add item" dropdown/item list, so staff
    // can tell apart same-named products across categories.
    public function test_variant_lookup_includes_category_name(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Love Bullet']);
        $product->variants()->create(['sku' => 'RYUKYAAA0002', 'sell_price' => 40000, 'current_stock' => 10]);

        $response = $this->getJson('/api/v1/variants/lookup?q=RYUKYAAA0002');

        $response->assertOk();
        $this->assertSame($category->name, $response->json('data.0.category_name'));
    }

    // 030-fix-pos-search-product-image — layar POS bergantung pada `image_url` hasil
    // pencarian: foto varian sendiri, kalau tak ada foto produk induk, kalau tak
    // ada keduanya null. Dulu tak ada tes untuk aturan ini (field-nya malah
    // terbuang di sisi view POS tanpa ada yang menyadari).
    public function test_variant_lookup_image_url_prefers_the_variants_own_photo(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Slippery', 'image_path' => 'products/induk.png']);
        $product->variants()->create(['sku' => 'SPFIMG0001', 'variant_name' => 'Besar', 'sell_price' => 40000, 'current_stock' => 9, 'image_path' => 'variants/sendiri.png']);

        $response = $this->getJson('/api/v1/variants/lookup?q=SPFIMG0001');

        $response->assertOk();
        $this->assertStringEndsWith('variants/sendiri.png', $response->json('data.0.image_url'));
    }

    public function test_variant_lookup_image_url_falls_back_to_the_products_photo(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Slippery', 'image_path' => 'products/induk.png']);
        $product->variants()->create(['sku' => 'SPFIMG0002', 'variant_name' => 'Kecil', 'sell_price' => 15000, 'current_stock' => 20]);

        // dicari lewat nama produk DAN lewat potongan SKU: foto yang sama
        $byName = $this->getJson('/api/v1/variants/lookup?q=Slippery');
        $bySku = $this->getJson('/api/v1/variants/lookup?q=IMG0002');

        $this->assertStringEndsWith('products/induk.png', $byName->json('data.0.image_url'));
        $this->assertStringEndsWith('products/induk.png', $bySku->json('data.0.image_url'));
    }

    public function test_variant_lookup_image_url_is_null_when_neither_variant_nor_product_has_a_photo(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Slippery', 'image_path' => null]);
        $product->variants()->create(['sku' => 'SPFIMG0003', 'sell_price' => 5000, 'current_stock' => 20]);

        $response = $this->getJson('/api/v1/variants/lookup?q=SPFIMG0003');

        $response->assertOk();
        $this->assertNull($response->json('data.0.image_url'));
    }

    public function test_variant_lookup_only_returns_active_variants(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000, 'is_active' => false]);

        $response = $this->getJson('/api/v1/variants/lookup?q=AAA0001');

        $this->assertCount(0, $response->json('data'));
    }

    // =====================================================================
    // Task 5 — unggah gambar produk.
    // =====================================================================

    public function test_owner_can_upload_a_product_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        $response = $this->post("/api/v1/products/{$product->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('produk.jpg'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('image_url'));
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($product->fresh()->image_path);
    }

    public function test_uploading_a_disguised_non_image_file_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        $response = $this->post("/api/v1/products/{$product->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->create('produk.jpg', 10, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_cashier_cannot_upload_a_product_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        $response = $this->post("/api/v1/products/{$product->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('produk.jpg'),
        ]);

        $response->assertStatus(403);
    }

    // =====================================================================
    // Per-variant image — added at the product owner's explicit request so
    // different variants of the same product can each have their own
    // picture, mirroring the Task 5 product-image tests above.
    // =====================================================================

    public function test_owner_can_upload_a_variant_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000]);

        $response = $this->post("/api/v1/variants/{$variant->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('varian.jpg'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('image_url'));
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($variant->fresh()->image_path);
    }

    public function test_uploading_a_disguised_non_image_file_as_variant_image_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000]);

        $response = $this->post("/api/v1/variants/{$variant->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->create('varian.jpg', 10, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_cashier_cannot_upload_a_variant_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000]);

        $response = $this->post("/api/v1/variants/{$variant->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('varian.jpg'),
        ]);

        $response->assertStatus(403);
    }

    public function test_a_variants_own_image_is_independent_of_its_products_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000]);

        $this->post("/api/v1/products/{$product->id}/image", ['image' => \Illuminate\Http\UploadedFile::fake()->image('produk.jpg')])->assertOk();
        $this->post("/api/v1/variants/{$variant->id}/image", ['image' => \Illuminate\Http\UploadedFile::fake()->image('varian.jpg')])->assertOk();

        $this->assertNotSame($product->fresh()->image_path, $variant->fresh()->image_path);
    }

    /** Membuat produk dengan varian (sku, stok, harga jual, aktif) untuk uji total daftar produk (048). */
    private function productWithVariants(array $variants, array $productAttrs = []): Product
    {
        // Factory tanpa kode tetap: baseline() memakai kode 'RYU' yang unik, jadi tidak bisa dipanggil berulang.
        $product = Product::factory()->create(array_merge([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
        ], $productAttrs));
        foreach ($variants as $i => [$stock, $price, $active]) {
            $product->variants()->create([
                'sku' => $product->code_prefix.'-'.($i + 1),
                'variant_name' => "V{$i}",
                'sell_price' => $price,
                'cost_price' => 999,
                'current_stock' => $stock,
                'is_active' => $active,
            ]);
        }

        return $product;
    }

    private function listRow(int $productId, string $query = ''): array
    {
        $rows = $this->getJson('/api/v1/products?per_page=100'.$query)->assertOk()->json('data');

        return collect($rows)->firstWhere('id', $productId);
    }

    /** Spec 048 US3 (FR-010..FR-013): total stok dan nilai stok = stok x harga JUAL, hanya varian aktif. */
    public function test_list_rows_carry_total_stock_and_stock_value_of_active_variants_only(): void
    {
        $this->actingAsRole('owner');
        $product = $this->productWithVariants([[4, 20000, true], [10, 20000, true], [0, 20000, false], [5, 1000, false]]);

        $row = $this->listRow($product->id);

        $this->assertSame(14, $row['total_stock']);
        $this->assertSame('280000.00', $row['total_stock_value']);
    }

    public function test_totals_use_selling_price_per_variant_and_never_cost(): void
    {
        $this->actingAsRole('owner');
        $product = $this->productWithVariants([[3, 10000, true], [2, 2500, true]]);

        $row = $this->listRow($product->id);

        // 3x10000 + 2x2500 = 35000; cost_price (999) must not influence the figure.
        $this->assertSame(5, $row['total_stock']);
        $this->assertSame('35000.00', $row['total_stock_value']);
    }

    public function test_product_without_variants_or_stock_shows_zero_totals(): void
    {
        $this->actingAsRole('owner');
        $empty = $this->productWithVariants([]);
        $zero = $this->productWithVariants([[0, 15000, true]]);

        $this->assertSame(0, $this->listRow($empty->id)['total_stock']);
        $this->assertSame('0.00', $this->listRow($empty->id)['total_stock_value']);
        $this->assertSame(0, $this->listRow($zero->id)['total_stock']);
        $this->assertSame('0.00', $this->listRow($zero->id)['total_stock_value']);
    }

    public function test_soft_deleted_variants_are_excluded_from_totals(): void
    {
        $this->actingAsRole('owner');
        $product = $this->productWithVariants([[4, 20000, true], [6, 20000, true]]);
        $product->variants()->where('variant_name', 'V1')->first()->delete();

        $row = $this->listRow($product->id);

        $this->assertSame(4, $row['total_stock']);
        $this->assertSame('80000.00', $row['total_stock_value']);
    }

    public function test_negative_stock_is_summed_as_is(): void
    {
        $this->actingAsRole('owner');
        $product = $this->productWithVariants([[-2, 10000, true], [5, 10000, true]]);

        $row = $this->listRow($product->id);

        $this->assertSame(3, $row['total_stock']);
        $this->assertSame('30000.00', $row['total_stock_value']);
    }

    /** Spec 048 edge case: DEMO dan LIVE tidak pernah dijumlahkan bersama. */
    public function test_totals_do_not_mix_demo_and_live_variants(): void
    {
        $this->actingAsRole('owner');
        $product = $this->productWithVariants([[4, 20000, true]]);
        ModeGate::runAs('demo', fn () => $product->variants()->create([
            'sku' => $product->code_prefix.'-9', 'variant_name' => 'Demo', 'sell_price' => 20000, 'current_stock' => 100, 'is_active' => true,
        ]));

        $row = $this->listRow($product->id);

        $this->assertSame(4, $row['total_stock']);
        $this->assertSame('80000.00', $row['total_stock_value']);
    }

    /** Total hanya dihitung query daftar: respons show/store/update tidak memuatnya (bukan nol palsu). */
    public function test_totals_are_absent_outside_the_list_endpoint(): void
    {
        $this->actingAsRole('owner');
        $product = $this->productWithVariants([[4, 20000, true]]);
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->getJson("/api/v1/products/{$product->id}")->assertOk()
            ->assertJsonMissingPath('total_stock')->assertJsonMissingPath('total_stock_value');
        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Rename'])->assertOk()
            ->assertJsonMissingPath('total_stock')->assertJsonMissingPath('total_stock_value');
        $this->postJson('/api/v1/products', $this->productPayload($artist->id, $category->id, ['product_segment' => 'ZZ']))->assertCreated()
            ->assertJsonMissingPath('total_stock')->assertJsonMissingPath('total_stock_value');
    }

    public function test_list_does_not_expose_cost_and_keeps_a_constant_query_count(): void
    {
        $this->actingAsRole('owner');
        foreach (range(1, 12) as $i) {
            $this->productWithVariants([[$i, 1000, true], [1, 500, true]]);
        }

        \DB::enableQueryLog();
        $small = $this->getJson('/api/v1/products?per_page=3')->assertOk();
        $smallCount = count(\DB::getQueryLog());
        \DB::flushQueryLog();
        $large = $this->getJson('/api/v1/products?per_page=12')->assertOk();
        $largeCount = count(\DB::getQueryLog());

        $this->assertSame($smallCount, $largeCount, 'Jumlah query tidak boleh tumbuh dengan ukuran halaman.');
        $this->assertStringNotContainsString('cost', strtolower(json_encode(collect($large->json('data'))->map(fn ($r) => array_keys($r))->all())));
    }
}
