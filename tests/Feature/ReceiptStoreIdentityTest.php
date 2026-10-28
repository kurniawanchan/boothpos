<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 079 / US2 — struk memuat identitas toko OUTLET TEMPAT PENJUALAN (bukan outlet yang sedang dipilih penonton):
 * nama, alamat, telepon, surel, catatan. Detail kosong dihilangkan; outlet tanpa detail sama sekali memakai blok
 * bersama; logo, footer, dan kontak person tetap bersama; telepon/surel pelanggan tidak pernah jadi kontak toko.
 */
class ReceiptStoreIdentityTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private Outlet $a;

    private Outlet $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'licensing']);
        foreach ([
            'store_name' => 'Toko Bersama', 'store_address' => 'Jl. Bersama 1', 'store_contact' => '021-111',
            'store_contact_phone' => '021-222', 'store_contact_email' => 'bersama@example.test',
            'store_contact_person' => 'Budi Pemilik', 'receipt_footer_text' => 'Terima kasih!',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string', 'group' => 'receipt']);
        }
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->a->update(['address' => 'Jl. A 10', 'contact_phone' => '0811-A', 'contact_email' => 'a@example.test', 'notes' => 'Catatan A']);
        $this->b->update(['address' => 'Jl. B 20', 'contact_phone' => '0822-B', 'contact_email' => 'b@example.test', 'notes' => 'Catatan B']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function saleAt(Outlet $outlet, array $over = []): int
    {
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['home_outlet_id' => $outlet->id])->id, 'sell_price' => 10000]);
        $cashier = User::factory()->create(['role' => 'cashier']);

        return $this->orderAt($this->shiftAt($outlet, $cashier), $variant, 1, $over)->id;
    }

    private function receipt(int $orderId, array $headers = [])
    {
        return $this->getJson("/api/v1/orders/{$orderId}/receipt", $headers)->assertOk();
    }

    public function test_each_receipt_shows_only_its_own_outlets_block(): void
    {
        $ra = $this->receipt($this->saleAt($this->a));
        $rb = $this->receipt($this->saleAt($this->b));

        $this->assertSame($this->a->name, $ra->json('store_name'));
        $this->assertSame('Jl. A 10', $ra->json('store_address'));
        $this->assertSame('0811-A', $ra->json('store_contact_phone'));
        $this->assertSame('0811-A', $ra->json('store_contact'));
        $this->assertSame('a@example.test', $ra->json('store_contact_email'));
        $this->assertSame('Catatan A', $ra->json('store_notes'));

        $this->assertSame($this->b->name, $rb->json('store_name'));
        $this->assertSame('Jl. B 20', $rb->json('store_address'));
        $this->assertSame('0822-B', $rb->json('store_contact_phone'));
        $this->assertSame('b@example.test', $rb->json('store_contact_email'));
        $this->assertSame('Catatan B', $rb->json('store_notes'));
    }

    public function test_the_receipt_belongs_to_the_sale_so_another_selected_outlet_never_shows_its_own_details(): void
    {
        $orderA = $this->saleAt($this->a);

        // Dengan outlet B terpilih, order outlet A tidak terlihat sama sekali (OutletScope): tidak ada struk yang bisa
        // memuat identitas B untuk penjualan A.
        $this->getJson("/api/v1/orders/{$orderA}/receipt", ['X-Outlet-Id' => $this->b->id])->assertNotFound();

        // Dengan outlet A terpilih, atau semua outlet, struk A memuat identitas A.
        foreach ([['X-Outlet-Id' => $this->a->id], []] as $headers) {
            $r = $this->receipt($orderA, $headers);
            $this->assertSame($this->a->name, $r->json('store_name'));
            $this->assertSame('Jl. A 10', $r->json('store_address'));
            $this->assertSame('Catatan A', $r->json('store_notes'));
        }
    }

    public function test_an_empty_detail_is_null_and_never_borrowed_from_shared_settings_or_another_outlet(): void
    {
        $this->a->update(['contact_phone' => null, 'notes' => null]);

        $r = $this->receipt($this->saleAt($this->a));

        $this->assertNull($r->json('store_contact_phone'));
        $this->assertNull($r->json('store_contact'));
        $this->assertNull($r->json('store_notes'));
        $this->assertSame('Jl. A 10', $r->json('store_address'));
        $this->assertSame('a@example.test', $r->json('store_contact_email'));
    }

    public function test_an_outlet_without_any_detail_prints_the_shared_block_like_before(): void
    {
        $this->a->update(['address' => null, 'contact_phone' => null, 'contact_email' => null, 'notes' => null]);

        $r = $this->receipt($this->saleAt($this->a));

        $this->assertSame('Toko Bersama', $r->json('store_name'));
        $this->assertSame('Jl. Bersama 1', $r->json('store_address'));
        $this->assertSame('021-222', $r->json('store_contact_phone'));
        $this->assertSame('021-111', $r->json('store_contact'));
        $this->assertSame('bersama@example.test', $r->json('store_contact_email'));
        $this->assertNull($r->json('store_notes'));
    }

    public function test_a_reprint_after_editing_the_outlet_shows_the_current_details(): void
    {
        $order = $this->saleAt($this->a);
        $this->assertSame('Jl. A 10', $this->receipt($order)->json('store_address'));

        $this->putJson("/api/v1/outlets/{$this->a->id}", ['name' => $this->a->name, 'address' => 'Jl. A Baru 99'])->assertOk();

        $this->assertSame('Jl. A Baru 99', $this->receipt($order)->json('store_address'));
    }

    public function test_logo_footer_app_name_and_contact_person_stay_shared_and_customer_contact_stays_customer_only(): void
    {
        $customer = \App\Models\Customer::factory()->create(['name' => 'Citra', 'phone' => '0899-CUST', 'email' => 'citra@example.test']);
        $r = $this->receipt($this->saleAt($this->a, ['customer_id' => $customer->id]));

        $this->assertSame('Terima kasih!', $r->json('receipt_footer_text'));
        $this->assertSame('Budi Pemilik', $r->json('store_contact_person'));
        $this->assertNotNull($r->json('app_name'));
        $this->assertSame('0899-CUST', $r->json('customer_phone'));
        $this->assertNotSame('0899-CUST', $r->json('store_contact_phone'));
        $this->assertNotSame('citra@example.test', $r->json('store_contact_email'));
    }
}
