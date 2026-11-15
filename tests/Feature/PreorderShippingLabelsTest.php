<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SellerScope\SellerScopeFixture;
use Tests\TestCase;

/**
 * 069-courier-shipping-label-a4 (T002) — `POST /preorders/shipping-labels`.
 *
 * Satu endpoint baca-saja untuk label kurir tunggal DAN massal: isinya data
 * pengiriman yang SUDAH TERSIMPAN (drawer detail mengedit `shipment` di tempat,
 * jadi hanya server yang punya versi tersimpan — research D1). Pre-order dibuat
 * langsung lewat model: yang diuji di sini adalah endpoint label, bukan alur
 * pembuatan pre-order.
 */
class PreorderShippingLabelsTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    private const URL = '/api/v1/preorders/shipping-labels';

    private const LABEL_KEYS = [
        'preorder_id', 'preorder_number', 'courier_name', 'tracking_number',
        'recipient_name', 'recipient_phone', 'address_line',
    ];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function makePreorder(string $fulfillment = 'courier', string $status = 'ordered', ?string $number = null): Preorder
    {
        $this->seq++;

        return Preorder::create([
            'preorder_number' => $number ?? sprintf('PO-20261115-%04d', $this->seq),
            'customer_id' => Customer::factory()->create(['email' => "c{$this->seq}@example.test"])->id,
            'user_id' => auth()->id(),
            'status' => $status,
            'fulfillment' => $fulfillment,
            'subtotal' => 100000,
            'shipping_cost' => 0,
            'total_amount' => 100000,
        ]);
    }

    private function withShipment(Preorder $preorder, array $overrides = []): Preorder
    {
        Shipment::create(array_merge([
            'preorder_id' => $preorder->id,
            'courier_name' => 'JNE',
            'tracking_number' => 'TRK'.$preorder->id,
            'recipient_name' => 'Penerima '.$preorder->id,
            'recipient_phone' => '0800000'.$preorder->id,
            'address_line' => 'Jalan Contoh '.$preorder->id,
            'status' => 'pending',
        ], $overrides));

        return $preorder;
    }

    public function test_a_mail_order_with_a_shipment_returns_exactly_the_label_fields_and_store_identity_once(): void
    {
        $p = $this->withShipment($this->makePreorder());

        $res = $this->postJson(self::URL, ['preorder_ids' => [$p->id]])->assertOk();

        $this->assertSame([], $res->json('skipped'));
        $label = $res->json('data.0');
        $this->assertEqualsCanonicalizing(self::LABEL_KEYS, array_keys($label));
        $this->assertSame($p->id, $label['preorder_id']);
        $this->assertSame($p->preorder_number, $label['preorder_number']);
        $this->assertSame('JNE', $label['courier_name']);
        $this->assertSame('TRK'.$p->id, $label['tracking_number']);
        $this->assertSame('Penerima '.$p->id, $label['recipient_name']);
        $this->assertSame('0800000'.$p->id, $label['recipient_phone']);
        $this->assertSame('Jalan Contoh '.$p->id, $label['address_line']);

        $this->assertEqualsCanonicalizing(
            ['name', 'address', 'contact_phone', 'contact_person'],
            array_keys($res->json('store_identity')),
        );
    }

    public function test_ineligible_preorders_are_skipped_with_a_reason_and_every_id_is_accounted_for(): void
    {
        $eligible = $this->withShipment($this->makePreorder());
        $noShipment = $this->makePreorder('courier');
        $pickup = $this->makePreorder('pickup');
        // Pernah Mail Order lalu diubah ke pickup: baris shipment masih ada, tetap bukan label kurir.
        $pickupWithShipment = $this->withShipment($this->makePreorder('pickup'));
        $demo = ModeGate::runAs('demo', fn () => $this->withShipment($this->makePreorder()));
        $missing = 999999;

        $ids = [$eligible->id, $noShipment->id, $pickup->id, $pickupWithShipment->id, $demo->id, $missing];
        $res = $this->postJson(self::URL, ['preorder_ids' => $ids])->assertOk();

        $this->assertSame([$eligible->id], array_column($res->json('data'), 'preorder_id'));
        $this->assertSame([
            ['preorder_id' => $noShipment->id, 'preorder_number' => $noShipment->preorder_number, 'reason' => 'no_shipment'],
            ['preorder_id' => $pickup->id, 'preorder_number' => $pickup->preorder_number, 'reason' => 'not_mail_order'],
            ['preorder_id' => $pickupWithShipment->id, 'preorder_number' => $pickupWithShipment->preorder_number, 'reason' => 'not_mail_order'],
            // Mode lain dan id yang tak ada terlihat SAMA (nomor tak dibocorkan).
            ['preorder_id' => $demo->id, 'preorder_number' => null, 'reason' => 'not_found'],
            ['preorder_id' => $missing, 'preorder_number' => null, 'reason' => 'not_found'],
        ], $res->json('skipped'));
        $this->assertSame(count($ids), count($res->json('data')) + count($res->json('skipped')));
    }

    public function test_labels_are_ordered_by_preorder_number_whatever_the_request_order(): void
    {
        $c = $this->withShipment($this->makePreorder(number: 'PO-20261115-0300'));
        $a = $this->withShipment($this->makePreorder(number: 'PO-20261115-0100'));
        $b = $this->withShipment($this->makePreorder(number: 'PO-20261115-0200'));

        $res = $this->postJson(self::URL, ['preorder_ids' => [$c->id, $a->id, $b->id]])->assertOk();

        $this->assertSame(
            ['PO-20261115-0100', 'PO-20261115-0200', 'PO-20261115-0300'],
            array_column($res->json('data'), 'preorder_number'),
        );
    }

    /** Hanya `tracking_number` yang boleh NULL di skema (recipient_phone/address_line NOT NULL). */
    public function test_status_is_not_a_filter_and_a_missing_tracking_number_comes_back_as_null(): void
    {
        $cancelled = $this->withShipment($this->makePreorder('courier', 'cancelled'), ['tracking_number' => null]);

        $label = $this->postJson(self::URL, ['preorder_ids' => [$cancelled->id]])->assertOk()->json('data.0');

        $this->assertSame($cancelled->id, $label['preorder_id']);
        $this->assertNull($label['tracking_number']);
    }

    public function test_invalid_requests_are_refused_with_422(): void
    {
        config(['preorders.bulk_max' => 3]);
        $p = $this->withShipment($this->makePreorder());

        $this->postJson(self::URL, [])->assertUnprocessable()->assertJsonValidationErrors('preorder_ids');
        $this->postJson(self::URL, ['preorder_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('preorder_ids');
        $this->postJson(self::URL, ['preorder_ids' => ['abc']])->assertUnprocessable()->assertJsonValidationErrors('preorder_ids.0');
        $this->postJson(self::URL, ['preorder_ids' => [$p->id, $p->id]])->assertUnprocessable();

        $tooMany = $this->postJson(self::URL, ['preorder_ids' => [1, 2, 3, 4]])->assertUnprocessable();
        $this->assertStringContainsString('3', $tooMany->json('errors.preorder_ids.0'));
    }

    public function test_a_role_without_the_preorders_export_action_is_refused(): void
    {
        $p = $this->withShipment($this->makePreorder());
        $role = Role::factory()->create(['menu_keys' => ['preorders'], 'menu_actions' => ['preorders' => ['edit']]]);
        $user = User::factory()->create();
        $user->update(['role_id' => $role->id]);
        $this->actingAs($user->fresh(), 'sanctum');

        $this->postJson(self::URL, ['preorder_ids' => [$p->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'action_not_allowed');
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson(self::URL, ['preorder_ids' => [1]])->assertUnauthorized();
    }

    public function test_a_seller_scoped_user_cannot_print_another_sellers_preorder(): void
    {
        $w = $this->buildSellerWorld();
        $p = $this->buildPreorders($w);
        foreach ([$p['pa'], $p['pb']] as $id) {
            Preorder::whereKey($id)->update(['fulfillment' => 'courier']);
            $this->withShipment(Preorder::findOrFail($id));
        }

        $this->actingAsSellerUser($w['a'], 'admin');
        $res = $this->postJson(self::URL, ['preorder_ids' => [$p['pa'], $p['pb']]])->assertOk();

        $this->assertSame([$p['pa']], array_column($res->json('data'), 'preorder_id'));
        $this->assertSame([['preorder_id' => $p['pb'], 'preorder_number' => null, 'reason' => 'not_found']], $res->json('skipped'));
    }

    public function test_the_payload_carries_no_customer_contact_price_item_or_payment_data(): void
    {
        $p = $this->withShipment($this->makePreorder());

        $body = $this->postJson(self::URL, ['preorder_ids' => [$p->id]])->assertOk()->getContent();

        $email = $p->customer->email;
        $this->assertStringNotContainsString($email, $body);
        foreach (['"items"', '"payments"', '"customer"', '"total_amount"', '"subtotal"', '"shipping_cost"', '"notes"', '"email"'] as $key) {
            $this->assertStringNotContainsString($key, $body, "payload label tidak boleh memuat {$key}");
        }
    }

    public function test_query_count_does_not_grow_with_the_number_of_labels(): void
    {
        $one = [$this->withShipment($this->makePreorder())->id];
        $many = [];
        for ($i = 0; $i < 20; $i++) {
            $many[] = $this->withShipment($this->makePreorder())->id;
        }

        // Pemanasan: cache setting/lisensi terisi pada permintaan pertama (catatan 048).
        $this->postJson(self::URL, ['preorder_ids' => $one])->assertOk();

        $count = function (array $ids): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson(self::URL, ['preorder_ids' => $ids])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($count($one), $count($many));
    }

    public function test_downloading_labels_changes_nothing(): void
    {
        $p = $this->withShipment($this->makePreorder());
        $before = [
            Preorder::count(), Shipment::count(), ActivityLog::count(),
            Preorder::find($p->id)->updated_at?->toIso8601String(),
            Shipment::where('preorder_id', $p->id)->first()->updated_at?->toIso8601String(),
        ];

        $this->travel(5)->minutes();
        $this->postJson(self::URL, ['preorder_ids' => [$p->id]])->assertOk();

        $this->assertSame($before, [
            Preorder::count(), Shipment::count(), ActivityLog::count(),
            Preorder::find($p->id)->updated_at?->toIso8601String(),
            Shipment::where('preorder_id', $p->id)->first()->updated_at?->toIso8601String(),
        ]);
    }
}
