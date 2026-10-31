<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 007-preorder-import-export-notify (US1) — search param on GET /preorders.
 */
class PreorderSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makePreorderFor(string $customerName, string $status = 'ordered'): void
    {
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true]);
        $variant = $product->variants()->create(['sku' => strtoupper(substr(md5($customerName), 0, 12)), 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);
        $customer = Customer::factory()->create(['name' => $customerName]);

        $response = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ]);

        if ($status !== 'ordered') {
            $this->patchJson("/api/v1/preorders/{$response->json('id')}/status", ['status' => $status]);
        }
    }

    protected function actingAsCashier(): User
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_search_matches_partial_case_insensitive_customer_name(): void
    {
        $this->actingAsCashier();
        $this->makePreorderFor('Siti Amalia');
        $this->makePreorderFor('Budi Santoso');

        $response = $this->getJson('/api/v1/preorders?search=siti');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Siti Amalia', $rows[0]['customer_name']);
    }

    public function test_search_with_no_match_returns_empty_list_not_error(): void
    {
        $this->actingAsCashier();
        $this->makePreorderFor('Siti Amalia');

        $response = $this->getJson('/api/v1/preorders?search=zzz-no-match');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_search_combines_with_existing_status_filter(): void
    {
        $this->actingAsCashier();
        $this->makePreorderFor('Siti Amalia', 'ordered');
        $this->makePreorderFor('Siti Rahayu', 'dp_paid');

        $response = $this->getJson('/api/v1/preorders?search=siti&status=dp_paid');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Siti Rahayu', $rows[0]['customer_name']);
    }

    /**
     * 052-preorder-detail-prev-next (research.md Decision 3) — baris dengan
     * nilai urutan yang SAMA dulu tidak punya urutan pasti di MySQL, jadi satu
     * pre-order bisa muncul di dua halaman (dan tetangga detail bisa melompat).
     * Pengikat preorders.id membuat urutan total dan stabil.
     */
    private function makeTiedPreorders(int $count): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = Preorder::create([
                'preorder_number' => sprintf('PO-20261001-%04d', $i),
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'status' => 'ordered',
                'fulfillment' => 'pickup',
            ])->id;
        }

        DB::table('preorders')->whereIn('id', $ids)->update(['created_at' => '2026-10-01 10:00:00']);

        return $ids;
    }

    private function idsAcrossPages(string $query, int $pages): array
    {
        $ids = [];
        for ($page = 1; $page <= $pages; $page++) {
            $ids = [...$ids, ...collect($this->getJson("/api/v1/preorders?per_page=10&page={$page}{$query}")->assertOk()->json('data'))->pluck('id')->all()];
        }

        return $ids;
    }

    public function test_list_order_is_stable_across_pages_when_sort_values_tie(): void
    {
        $this->actingAsCashier();
        $ids = $this->makeTiedPreorders(30);

        $listed = $this->idsAcrossPages('', 3);

        $this->assertCount(30, array_unique($listed));
        $expected = $ids;
        rsort($expected);
        $this->assertSame($expected, $listed);
    }

    public function test_explicit_sort_breaks_ties_by_id_in_the_same_direction(): void
    {
        $this->actingAsCashier();
        $ids = $this->makeTiedPreorders(30);

        $listed = $this->idsAcrossPages('&sort_by=status&sort_dir=asc', 3);

        $expected = $ids;
        sort($expected);
        $this->assertSame($expected, $listed);
    }
}
