<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 021-preorder-form-updates — mirrors tests/Feature/PreorderTest.php's
 * setup (one variant at 300000, one customer, acting as cashier since
 * preorder endpoints are open to every authenticated role).
 */
class PreorderFormUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'RYUKYFIG0001', 'sell_price' => 300000, 'cost_price' => 150000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/preorders', array_merge([
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], $overrides));
    }

    // --- US2: Discount -----------------------------------------------------

    public function test_creating_a_preorder_with_a_discount_reduces_total_amount_accordingly(): void
    {
        $response = $this->createPreorder(['discount' => 50000]);

        $response->assertCreated();
        $this->assertEquals('300000.00', $response->json('subtotal'));
        $this->assertEquals('50000.00', $response->json('discount'));
        $this->assertEquals('250000.00', $response->json('total_amount'));
    }

    public function test_a_discount_larger_than_subtotal_plus_shipping_is_rejected(): void
    {
        $response = $this->createPreorder(['discount' => 300001]);

        $response->assertStatus(422)->assertJsonValidationErrors('discount');
        $this->assertDatabaseCount('preorders', 0);
    }

    public function test_omitting_discount_behaves_exactly_as_before(): void
    {
        $response = $this->createPreorder();

        $response->assertCreated();
        $this->assertEquals('0.00', $response->json('discount'));
        $this->assertEquals('300000.00', $response->json('total_amount'));
    }

    // --- US3: pickup day, courier, fulfillment relabel ----------------------

    public function test_pickup_day_is_accepted_when_it_falls_within_the_linked_events_date_range(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);

        $response = $this->createPreorder([
            'event_id' => $event->id,
            'fulfillment' => 'pickup',
            'pickup_day' => '2026-10-13',
        ]);

        $response->assertCreated();
        $this->assertEquals('2026-10-13', $response->json('pickup_day'));
    }

    public function test_pickup_day_is_rejected_when_outside_the_events_date_range(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);

        $response = $this->createPreorder([
            'event_id' => $event->id,
            'fulfillment' => 'pickup',
            'pickup_day' => '2026-10-15',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('pickup_day');
        $this->assertDatabaseCount('preorders', 0);
    }

    public function test_pickup_day_is_rejected_when_no_event_is_linked(): void
    {
        $response = $this->createPreorder([
            'fulfillment' => 'pickup',
            'pickup_day' => '2026-10-13',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('pickup_day');
        $this->assertDatabaseCount('preorders', 0);
    }

    public function test_pickup_day_is_cleared_when_the_linked_events_dates_change_to_exclude_it(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-14']);
        $preorder = $this->createPreorder([
            'event_id' => $event->id,
            'fulfillment' => 'pickup',
            'pickup_day' => '2026-10-14',
        ])->json();

        // EventPolicy::update() requires canAccessMenu('settings') —
        // cashier (this test class's default actor) doesn't have it.
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $this->patchJson("/api/v1/events/{$event->id}", [
            'name' => $event->name,
            'location' => $event->location,
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-13',
        ])->assertOk();

        $this->assertNull(\App\Models\Preorder::find($preorder['id'])->pickup_day);
    }

    public function test_courier_defaults_to_jne_when_omitted_for_mail_order_fulfillment(): void
    {
        $response = $this->createPreorder(['fulfillment' => 'courier']);

        $response->assertCreated();
        $this->assertEquals('JNE', $response->json('courier_name'));
    }

    public function test_an_unknown_courier_value_is_rejected(): void
    {
        $response = $this->createPreorder(['fulfillment' => 'courier', 'courier_name' => 'Wahana']);

        $response->assertStatus(422)->assertJsonValidationErrors('courier_name');
        $this->assertDatabaseCount('preorders', 0);
    }

    public function test_pickup_day_on_a_mail_order_fulfillment_row_is_rejected(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);

        $response = $this->createPreorder([
            'event_id' => $event->id,
            'fulfillment' => 'courier',
            'pickup_day' => '2026-10-12',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('pickup_day');
    }

    public function test_courier_name_on_a_self_pickup_fulfillment_row_is_rejected(): void
    {
        $response = $this->createPreorder([
            'fulfillment' => 'pickup',
            'courier_name' => 'JNE',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('courier_name');
    }

    // --- US4: import/export sync --------------------------------------------

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    private function writeXlsx(array $rows): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $i => $row) {
            $sheet->fromArray($row, null, 'A'.($i + 1));
        }
        $path = storage_path('app/test-preorder-form-updates-'.uniqid().'.xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return $path;
    }

    // 022-preorder-invoice-crud-overhaul (US7) — the row-per-item layout
    // these tests originally used has been fully replaced by a
    // one-row-per-order layout; see tests/Feature/PreorderExportImportTest.php
    // for that layout's own dedicated coverage. These three tests are
    // rewritten in place (not deleted) to keep proving the same
    // discount/pickup_day/courier_name cross-field rules survive import,
    // now expressed in the new column shape.
    private const IMPORT_HEADINGS = [
        'customer_name', 'event_name', 'fulfillment', 'pickup_day',
        'products', 'quantities', 'unit_prices',
        'shipping_cost', 'courier_name', 'expected_date', 'discount', 'notes',
    ];

    public function test_export_includes_discount_pickup_day_and_courier_name_columns(): void
    {
        $this->actingAsOwner();
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);
        $this->createPreorder(['event_id' => $event->id, 'fulfillment' => 'pickup', 'pickup_day' => '2026-10-12', 'discount' => 10000]);

        $response = $this->getJson('/api/v1/preorders/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_applies_discount_pickup_day_and_courier_name_exactly_as_the_form_would(): void
    {
        $this->actingAsOwner();
        $event = Event::factory()->create(['start_date' => '2026-10-12', 'end_date' => '2026-10-13']);
        $variant = $this->variant;

        $rows = [
            self::IMPORT_HEADINGS,
            ['Pelanggan Impor', $event->name, 'pickup', 'Day 2', $variant->sku, '1', '300000', '', '', '', '50000', ''],
        ];
        $file = new \Illuminate\Http\UploadedFile($this->writeXlsx($rows), 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(201);
        $preorder = \App\Models\Preorder::findOrFail($response->json('preorder_ids.0'));
        $this->assertEquals('50000.00', $preorder->discount);
        $this->assertEquals('2026-10-13', $preorder->pickup_day->toDateString());
        $this->assertEquals('250000.00', $preorder->total_amount);
    }

    public function test_import_rejects_a_row_with_pickup_day_set_on_a_mail_order_fulfillment_row(): void
    {
        $this->actingAsOwner();
        $variant = $this->variant;

        $rows = [
            self::IMPORT_HEADINGS,
            ['Pelanggan Impor', '', 'mail order', 'Day 2', $variant->sku, '1', '300000', '', '', '', '', ''],
        ];
        $file = new \Illuminate\Http\UploadedFile($this->writeXlsx($rows), 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('preorders', 0);
    }

    public function test_import_rejects_a_row_with_courier_name_set_on_a_self_pickup_fulfillment_row(): void
    {
        $this->actingAsOwner();
        $variant = $this->variant;

        $rows = [
            self::IMPORT_HEADINGS,
            ['Pelanggan Impor', '', 'pickup', '', $variant->sku, '1', '300000', '', 'JNE', '', '', ''],
        ];
        $file = new \Illuminate\Http\UploadedFile($this->writeXlsx($rows), 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('preorders', 0);
    }
}
