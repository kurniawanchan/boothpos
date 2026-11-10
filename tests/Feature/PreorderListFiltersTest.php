<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 066-preorder-filter-export-columns (US1, US2) — filter Customer (`customer_id[]`)
 * dan Kategori produk (`category_id[]`) pada daftar pre-order, serta bukti
 * bahwa summary / matching-ids / neighbors memakai himpunan baris yang SAMA
 * (satu scope: Preorder::scopeMatchingListFilters).
 */
class PreorderListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    /** Varian milik produk baru: kategori & penjual bisa ditentukan pemanggil. */
    private function makeVariant(?Category $category = null, ?Artist $artist = null): ProductVariant
    {
        $this->seq++;
        $product = Product::factory()->create([
            'artist_id' => ($artist ?? Artist::factory()->create())->id,
            'category_id' => ($category ?? Category::factory()->create())->id,
            'is_preorder' => true,
        ]);

        return $product->variants()->create([
            'sku' => 'FLT-'.$this->seq, 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
    }

    /**
     * Pre-order dengan satu item per varian yang diberikan (langsung lewat model:
     * yang diuji di sini adalah filter, bukan alur pembuatan pre-order).
     *
     * @param  array<int, ProductVariant>  $variants
     */
    private function makePreorder(Customer $customer, array $variants = [], string $status = 'ordered'): Preorder
    {
        $this->seq++;
        $variants = $variants ?: [$this->makeVariant()];

        $preorder = Preorder::create([
            'preorder_number' => sprintf('PO-20261110-%04d', $this->seq),
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'status' => $status,
            'fulfillment' => 'pickup',
            'subtotal' => 100000 * count($variants),
            'total_amount' => 100000 * count($variants),
        ]);

        foreach ($variants as $variant) {
            $preorder->items()->create([
                'variant_id' => $variant->id,
                'artist_id' => $variant->product->artist_id,
                'sku_snapshot' => $variant->sku,
                'name_snapshot' => 'Item '.$variant->sku,
                'qty' => 1, 'cost_price' => 50000, 'sell_price' => 100000, 'line_total' => 100000,
            ]);
        }

        return $preorder;
    }

    /** @return array<int, int> id baris dari GET /preorders (semua halaman cukup 1 di data uji) */
    private function listIds(string $query): array
    {
        return collect($this->getJson('/api/v1/preorders?per_page=100&'.$query)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------ US1: customer

    public function test_scalar_customer_id_still_filters(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $pa = $this->makePreorder($a);
        $this->makePreorder($b);

        $this->assertSame([$pa->id], $this->listIds('customer_id='.$a->id));
    }

    public function test_customer_id_array_returns_the_union_of_the_selected_customers(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $c = Customer::factory()->create();
        $pa = $this->makePreorder($a);
        $pb = $this->makePreorder($b);
        $this->makePreorder($c);

        $ids = $this->listIds('customer_id[]='.$a->id.'&customer_id[]='.$b->id);

        $this->assertSame($this->sorted([$pa->id, $pb->id]), $this->sorted($ids));
    }

    public function test_customer_filter_combines_with_status_as_an_intersection(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $this->makePreorder($a, [], 'ordered');
        $paid = $this->makePreorder($a, [], 'dp_paid');
        $this->makePreorder($b, [], 'dp_paid');

        $this->assertSame([$paid->id], $this->listIds('customer_id[]='.$a->id.'&status[]=dp_paid'));
    }

    public function test_an_unknown_customer_id_returns_an_empty_list_not_an_error(): void
    {
        $this->makePreorder(Customer::factory()->create());

        $this->assertSame([], $this->listIds('customer_id[]=999999'));
    }

    public function test_a_customer_from_the_other_data_mode_never_matches(): void
    {
        $this->makePreorder(Customer::factory()->create());
        $demoCustomer = ModeGate::runAs('demo', fn () => Customer::factory()->create());
        ModeGate::runAs('demo', fn () => $this->makePreorder($demoCustomer));

        // Mode aktif = live: baris demo tidak boleh muncul walau id pelanggannya diminta.
        $this->assertSame([], $this->listIds('customer_id[]='.$demoCustomer->id));
    }

    public function test_a_filtered_list_has_no_row_on_two_pages(): void
    {
        $a = Customer::factory()->create();
        $other = Customer::factory()->create();
        $expected = [];
        for ($i = 0; $i < 5; $i++) {
            $expected[] = $this->makePreorder($a)->id;
            $this->makePreorder($other);
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $res = $this->getJson('/api/v1/preorders?per_page=2&page='.$page.'&customer_id[]='.$a->id)->assertOk();
            $this->assertSame(5, $res->json('meta.total'));
            $seen = array_merge($seen, collect($res->json('data'))->pluck('id')->all());
        }

        $this->assertSame($this->sorted($expected), $this->sorted($seen));
        $this->assertCount(5, array_unique($seen));
    }

    public function test_summary_matching_ids_and_neighbors_follow_the_customer_filter(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $a1 = $this->makePreorder($a);
        $mid = $this->makePreorder($b);
        $a2 = $this->makePreorder($a);
        $filter = 'customer_id[]='.$a->id;

        $this->assertSame(2, $this->getJson('/api/v1/preorders/summary?'.$filter)->assertOk()->json('transaction_count'));

        $matching = $this->getJson('/api/v1/preorders/matching-ids?'.$filter)->assertOk();
        $this->assertSame($this->sorted([$a1->id, $a2->id]), $this->sorted($matching->json('ids')));
        $this->assertSame(2, $matching->json('total'));

        // Urutan bawaan created_at desc, id desc → a2, (mid), a1. Dengan filter, tetangga a2 adalah a1 — bukan `mid`.
        $filtered = $this->getJson("/api/v1/preorders/{$a2->id}/neighbors?".$filter)->assertOk()->json();
        $this->assertTrue($filtered['in_list']);
        $this->assertSame(2, $filtered['total']);
        $this->assertSame($a1->id, $filtered['next']['id']);

        $unfiltered = $this->getJson("/api/v1/preorders/{$a2->id}/neighbors")->assertOk()->json();
        $this->assertSame($mid->id, $unfiltered['next']['id']);
    }

    // ----------------------------------------------------------------- US2: category

    public function test_a_preorder_with_two_items_of_the_category_appears_once(): void
    {
        $x = Category::factory()->create();
        $customer = Customer::factory()->create();
        $po = $this->makePreorder($customer, [$this->makeVariant($x), $this->makeVariant($x)]);
        $this->makePreorder($customer); // kategori lain

        $res = $this->getJson('/api/v1/preorders?per_page=100&category_id[]='.$x->id)->assertOk();

        $this->assertSame([$po->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_a_mixed_preorder_is_returned_whole_not_only_the_matching_items(): void
    {
        $x = Category::factory()->create();
        $y = Category::factory()->create();
        $po = $this->makePreorder(Customer::factory()->create(), [$this->makeVariant($x), $this->makeVariant($y)]);
        $this->makePreorder(Customer::factory()->create()); // pengecoh kategori lain — tanpa ini tes lulus walau filter diabaikan

        $this->assertSame([$po->id], $this->listIds('category_id[]='.$x->id));
        $this->getJson("/api/v1/preorders/{$po->id}")->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_two_categories_return_the_union(): void
    {
        $x = Category::factory()->create();
        $y = Category::factory()->create();
        $z = Category::factory()->create();
        $customer = Customer::factory()->create();
        $px = $this->makePreorder($customer, [$this->makeVariant($x)]);
        $py = $this->makePreorder($customer, [$this->makeVariant($y)]);
        $this->makePreorder($customer, [$this->makeVariant($z)]);

        $ids = $this->listIds('category_id[]='.$x->id.'&category_id[]='.$y->id);

        $this->assertSame($this->sorted([$px->id, $py->id]), $this->sorted($ids));
    }

    public function test_category_combines_with_customer_seller_status_and_search_as_an_intersection(): void
    {
        $x = Category::factory()->create();
        $seller = Artist::factory()->create();
        $a = Customer::factory()->create(['name' => 'Siti Amalia']);
        $b = Customer::factory()->create(['name' => 'Budi Santoso']);

        $target = $this->makePreorder($a, [$this->makeVariant($x, $seller)], 'dp_paid');
        $this->makePreorder($b, [$this->makeVariant($x, $seller)], 'dp_paid');                // pelanggan lain
        $this->makePreorder($a, [$this->makeVariant($x, $seller)], 'ordered');                // status lain
        $this->makePreorder($a, [$this->makeVariant($x)], 'dp_paid');                         // penjual lain
        $this->makePreorder($a, [$this->makeVariant(null, $seller)], 'dp_paid');              // kategori lain

        $ids = $this->listIds(implode('&', [
            'category_id[]='.$x->id,
            'customer_id[]='.$a->id,
            'artist_id[]='.$seller->id,
            'status[]=dp_paid',
            'search=siti',
        ]));

        $this->assertSame([$target->id], $ids);
    }

    public function test_a_soft_deleted_variant_or_product_still_matches_so_history_stays_findable(): void
    {
        $x = Category::factory()->create();
        $customer = Customer::factory()->create();
        $deletedVariant = $this->makeVariant($x);
        $deletedProduct = $this->makeVariant($x);
        $poVariant = $this->makePreorder($customer, [$deletedVariant]);
        $poProduct = $this->makePreorder($customer, [$deletedProduct]);
        $this->makePreorder($customer); // pengecoh kategori lain — tanpa ini tes lulus walau filter diabaikan

        $deletedVariant->delete();
        $deletedProduct->delete();
        $deletedProduct->product->delete();

        $this->assertSame(
            $this->sorted([$poVariant->id, $poProduct->id]),
            $this->sorted($this->listIds('category_id[]='.$x->id)),
        );
    }

    public function test_a_category_without_preorders_returns_an_empty_list(): void
    {
        $this->makePreorder(Customer::factory()->create());

        $this->assertSame([], $this->listIds('category_id[]='.Category::factory()->create()->id));
    }

    public function test_a_category_from_the_other_data_mode_never_matches(): void
    {
        $this->makePreorder(Customer::factory()->create());
        $demoCategory = ModeGate::runAs('demo', fn () => Category::factory()->create());
        ModeGate::runAs('demo', fn () => $this->makePreorder(
            Customer::factory()->create(),
            [$this->makeVariant($demoCategory)],
        ));

        $this->assertSame([], $this->listIds('category_id[]='.$demoCategory->id));
    }

    public function test_summary_matching_ids_and_neighbors_follow_the_category_filter(): void
    {
        $x = Category::factory()->create();
        $customer = Customer::factory()->create();
        $x1 = $this->makePreorder($customer, [$this->makeVariant($x)]);
        $mid = $this->makePreorder($customer);
        $x2 = $this->makePreorder($customer, [$this->makeVariant($x)]);
        $filter = 'category_id[]='.$x->id;

        $this->assertSame(2, $this->getJson('/api/v1/preorders/summary?'.$filter)->assertOk()->json('transaction_count'));

        $matching = $this->getJson('/api/v1/preorders/matching-ids?'.$filter)->assertOk();
        $this->assertSame($this->sorted([$x1->id, $x2->id]), $this->sorted($matching->json('ids')));
        $this->assertSame(2, $matching->json('total'));

        $filtered = $this->getJson("/api/v1/preorders/{$x2->id}/neighbors?".$filter)->assertOk()->json();
        $this->assertTrue($filtered['in_list']);
        $this->assertSame(2, $filtered['total']);
        $this->assertSame($x1->id, $filtered['next']['id']);
        $this->assertNotSame($mid->id, $filtered['next']['id']);
    }
    // ------------------------------------------------- bendera ongkir/alamat (shipping_flag)

    /** Mail order dengan ongkir & alamat pelanggan yang ditentukan; `null` alamat = belum diisi. */
    private function makeCourier(float $shipping, ?string $address, string $fulfillment = 'courier'): Preorder
    {
        $po = $this->makePreorder(Customer::factory()->create(['address' => $address]));
        $po->update(['fulfillment' => $fulfillment, 'shipping_cost' => $shipping]);

        return $po;
    }

    public function test_shipping_flag_filters_by_each_cause_and_by_both(): void
    {
        $noCost = $this->makeCourier(0, 'Jl. Mawar 1');
        $noAddress = $this->makeCourier(15000, null);
        $blankAddress = $this->makeCourier(15000, '   ');   // spasi saja = belum diisi (sama dengan filled() di layar)
        $both = $this->makeCourier(0, null);
        $this->makeCourier(15000, 'Jl. Melati 2');           // lengkap → tidak ditandai
        $this->makeCourier(0, null, 'pickup');               // ambil sendiri tidak pernah ditandai

        $this->assertSame($this->sorted([$noCost->id, $both->id]), $this->sorted($this->listIds('shipping_flag[]=no_cost')));
        $this->assertSame(
            $this->sorted([$noAddress->id, $blankAddress->id, $both->id]),
            $this->sorted($this->listIds('shipping_flag[]=no_address')),
        );
        $this->assertSame(
            $this->sorted([$noCost->id, $noAddress->id, $blankAddress->id, $both->id]),
            $this->sorted($this->listIds('shipping_flag[]=no_cost&shipping_flag[]=no_address')),
        );
    }

    public function test_an_unknown_shipping_flag_value_is_ignored_not_treated_as_match_nothing(): void
    {
        $this->makeCourier(15000, 'Jl. Melati 2');
        $this->makeCourier(0, null);

        $this->assertCount(2, $this->listIds('shipping_flag[]=bukan_bendera'));
    }

    public function test_shipping_flag_combines_with_other_filters_and_feeds_summary_and_matching_ids(): void
    {
        $flagged = $this->makeCourier(0, null);
        $flagged->update(['status' => 'dp_paid']);
        $this->makeCourier(0, null);                          // ditandai tapi status lain
        $complete = $this->makeCourier(15000, 'Jl. Melati 2');  // status cocok tapi tidak ditandai — pengecoh: tanpa filter bendera ikut terambil
        $complete->update(['status' => 'dp_paid']);
        $filter = 'shipping_flag[]=no_cost&status[]=dp_paid';

        $this->assertSame([$flagged->id], $this->listIds($filter));
        $this->assertSame(1, $this->getJson('/api/v1/preorders/summary?'.$filter)->assertOk()->json('transaction_count'));
        $this->assertSame([$flagged->id], $this->getJson('/api/v1/preorders/matching-ids?'.$filter)->assertOk()->json('ids'));
    }
}
