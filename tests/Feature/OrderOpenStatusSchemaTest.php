<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 — skema status 'open' pada orders (data-model.md) dan jaminan
 * "satu tab terbuka per meja" lewat kolom generated + UNIQUE (research.md
 * Keputusan 10).
 */
class OrderOpenStatusSchemaTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    public function test_open_is_an_accepted_status(): void
    {
        $order = $this->makeOpenOrder($this->makeShift($this->cashier), $this->cashier);

        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_status_still_defaults_to_completed_when_omitted(): void
    {
        $session = $this->makeShift($this->cashier);

        // Jalur lama (seeder/test yang memanggil Order::create tanpa status)
        // tidak boleh berubah perilakunya: default DB tetap 'completed'.
        $order = Order::create([
            'order_number' => 'TRX-TEST-DEFAULT',
            'event_id' => $session->event_id, 'session_id' => $session->id, 'user_id' => $this->cashier->id,
            'channel' => 'offline', 'subtotal' => 0, 'total_amount' => 0, 'total_cost' => 0,
            'paid_amount' => 0, 'change_amount' => 0,
        ]);

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('retail', $fresh->service_type);
        $this->assertNull($fresh->table_id);
    }

    public function test_a_second_open_order_on_the_same_table_is_rejected_by_the_database(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->create();

        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'service_type' => 'dine_in']);

        $this->expectException(QueryException::class);
        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'service_type' => 'dine_in']);
    }

    public function test_non_open_orders_and_tableless_tabs_never_collide(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->create();

        // Satu terbuka + banyak selesai/batal pada meja yang sama: boleh.
        $open = $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'service_type' => 'dine_in']);
        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'status' => 'completed']);
        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'status' => 'completed']);
        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'status' => 'voided']);

        // Beberapa tab takeaway (tanpa meja) sekaligus: boleh.
        $this->makeOpenOrder($session, $this->cashier, ['service_type' => 'takeaway', 'tab_label' => 'A']);
        $this->makeOpenOrder($session, $this->cashier, ['service_type' => 'takeaway', 'tab_label' => 'B']);

        $this->assertSame($table->id, (int) DB::table('orders')->where('id', $open->id)->value('open_table_key'));
        $this->assertSame(0, DB::table('orders')->where('status', '!=', 'open')->whereNotNull('open_table_key')->count());
    }

    public function test_the_table_is_free_again_once_its_tab_is_completed(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->create();

        $first = $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'service_type' => 'dine_in']);
        $first->update(['status' => 'completed']);

        $second = $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'service_type' => 'dine_in']);

        $this->assertSame('open', $second->fresh()->status);
    }

    public function test_rolling_back_the_schema_migration_is_refused_while_an_open_order_exists(): void
    {
        $this->makeOpenOrder($this->makeShift($this->cashier), $this->cashier);

        $migration = require database_path('migrations/2026_10_31_000006_add_tab_columns_to_orders_items_and_payments.php');

        // down() harus melempar SEBELUM mengubah skema apa pun (DDL MySQL
        // tidak ikut transaksi — kalau ia sempat jalan, test ini merusak DB).
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /**
     * Rollback berjalan dari migrasi TERAKHIR: bila penjaga hanya ada di 000006, migrasi 000008/000007
     * (yang menghapus tabel tiket dapur) sudah berjalan sebelum penolakan — pernah terjadi pada data nyata.
     * Maka SETIAP migrasi fitur yang destruktif harus menolak sendiri, sebelum mengubah apa pun.
     */
    public function test_every_destructive_rollback_refuses_before_touching_anything_while_a_tab_is_open(): void
    {
        $this->makeOpenOrder($this->makeShift($this->cashier), $this->cashier);

        foreach ([
            '2026_10_31_000008_add_restaurant_menu_keys_to_default_roles.php',
            '2026_10_31_000007_create_kitchen_tickets_and_items_tables.php',
            '2026_10_31_000006_add_tab_columns_to_orders_items_and_payments.php',
        ] as $file) {
            $migration = require database_path("migrations/{$file}");

            try {
                $migration->down();
                $this->fail("{$file}::down() seharusnya menolak selama ada tab terbuka");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Rollback ditolak', $e->getMessage());
            }
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('kitchen_tickets'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('orders', 'open_table_key'));
    }
}
