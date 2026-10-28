<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Setting;
use App\Support\StoreIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 079 — StoreIdentity adalah satu-satunya penentu blok identitas toko. Struk: outlet transaksi, detail kosong
 * dihilangkan, kecuali outlet tanpa detail sama sekali (blok bersama utuh). Layar: outlet terpilih, jatuh ke nilai
 * bersama per kolom, "semua outlet" = nilai bersama.
 */
class StoreIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'store_name' => 'Toko Bersama',
            'store_address' => 'Jl. Bersama 1',
            'store_contact' => '021-111',
            'store_contact_phone' => '021-222',
            'store_contact_email' => 'bersama@example.test',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string']);
        }
    }

    private function outlet(array $attrs = []): Outlet
    {
        return Outlet::factory()->create(array_merge([
            'name' => 'Outlet A',
            'address' => 'Jl. A 10',
            'contact_phone' => '0811-A',
            'contact_email' => 'a@example.test',
            'notes' => 'Catatan A',
        ], $attrs));
    }

    public function test_receipt_uses_the_outlets_own_full_block(): void
    {
        $id = StoreIdentity::forReceipt($this->outlet());

        $this->assertSame('Outlet A', $id['name']);
        $this->assertSame('Jl. A 10', $id['address']);
        $this->assertSame('0811-A', $id['phone']);
        $this->assertSame('a@example.test', $id['email']);
        $this->assertSame('Catatan A', $id['notes']);
        $this->assertSame('0811-A', $id['contact']);
    }

    public function test_receipt_omits_empty_details_instead_of_borrowing_shared_or_other_outlet_values(): void
    {
        $this->outlet(['name' => 'Outlet B', 'address' => 'Jl. B 20']);
        $id = StoreIdentity::forReceipt($this->outlet(['name' => 'Outlet C', 'address' => 'Jl. C 30', 'contact_phone' => '  ', 'notes' => null]));

        $this->assertSame('Outlet C', $id['name']);
        $this->assertSame('Jl. C 30', $id['address']);
        $this->assertNull($id['phone']);
        $this->assertNull($id['notes']);
        $this->assertSame('a@example.test', $id['email']);
    }

    public function test_receipt_of_an_outlet_without_any_detail_uses_the_whole_shared_block(): void
    {
        $outlet = $this->outlet(['address' => null, 'contact_phone' => null, 'contact_email' => null, 'notes' => null]);
        $id = StoreIdentity::forReceipt($outlet);

        $this->assertSame('Toko Bersama', $id['name']);
        $this->assertSame('Jl. Bersama 1', $id['address']);
        $this->assertSame('021-222', $id['phone']);
        $this->assertSame('bersama@example.test', $id['email']);
        $this->assertNull($id['notes']);
        $this->assertSame('021-111', $id['contact']);
    }

    public function test_screen_without_outlet_is_the_shared_identity(): void
    {
        $id = StoreIdentity::forScreen(null);

        $this->assertSame('Toko Bersama', $id['name']);
        $this->assertSame('Jl. Bersama 1', $id['address']);
        $this->assertSame('021-222', $id['phone']);
    }

    public function test_screen_for_an_outlet_falls_back_per_field_to_the_shared_value(): void
    {
        $id = StoreIdentity::forScreen($this->outlet(['contact_phone' => null, 'notes' => null]));

        $this->assertSame('Outlet A', $id['name']);
        $this->assertSame('Jl. A 10', $id['address']);
        $this->assertSame('021-222', $id['phone']);
        $this->assertSame('a@example.test', $id['email']);
        $this->assertNull($id['notes']);
    }

    public function test_demo_mode_reads_the_demo_store_name_for_the_shared_identity(): void
    {
        Setting::updateOrCreate(['key' => 'store_name_demo'], ['value' => 'Toko Demo', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string']);

        $this->assertSame('Toko Demo', StoreIdentity::forScreen(null)['name']);
    }
}
