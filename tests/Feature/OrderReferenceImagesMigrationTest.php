<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Event;
use App\Models\LicenseActivation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 055 — migrasi gambar referensi tunggal (054: `orders.reference_image_path|mime`) ke tabel
 * `order_reference_images`, lalu kolom lama dibuang.
 *
 * SENGAJA TANPA RefreshDatabase: migrasi ini menjalankan DDL, dan DDL di MySQL melakukan COMMIT implisit,
 * sehingga transaksi pembungkus RefreshDatabase akan putus (rollback-nya lalu gagal "no active transaction").
 * Karena itu test ini membuat barisnya sendiri dan menghapusnya sendiri di tearDown(). Guard di TestCase
 * tetap memastikan ini hanya pernah berjalan di database *_test.
 */
class OrderReferenceImagesMigrationTest extends TestCase
{
    private object $migration;

    /** @var array<string,list<int>> */
    private array $created = ['orders' => [], 'cashier_sessions' => [], 'events' => [], 'users' => [], 'license_activations' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->created['license_activations'][] = (int) LicenseActivation::query()->max('id');
        $this->artisan('migrate', ['--force' => true]);
        $this->migration = require database_path('migrations/2026_11_22_000001_create_order_reference_images_table.php');
    }

    protected function tearDown(): void
    {
        // Skema harus kembali ke bentuk 055 apa pun yang terjadi di tengah test.
        if (! Schema::hasTable('order_reference_images')) {
            $this->migration->up();
        }
        DB::table('order_reference_images')->whereIn('order_id', $this->created['orders'])->delete();
        DB::table('orders')->whereIn('id', $this->created['orders'])->delete();
        DB::table('cashier_sessions')->whereIn('id', $this->created['cashier_sessions'])->delete();
        DB::table('events')->whereIn('id', $this->created['events'])->delete();
        DB::table('users')->whereIn('id', $this->created['users'])->delete();
        DB::table('license_activations')->whereIn('id', $this->created['license_activations'])->delete();

        parent::tearDown();
    }

    private function legacyOrder(string $mode, ?string $path, ?string $mime, ?int $editor): int
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create();
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);
        array_push($this->created['users'], $user->id);
        $this->created['events'][] = $event->id;
        $this->created['cashier_sessions'][] = $session->id;

        $id = DB::table('orders')->insertGetId([
            'order_number' => 'MIG-'.Str::upper(Str::random(10)), 'event_id' => $event->id, 'session_id' => $session->id,
            'user_id' => $user->id, 'data_mode' => $mode, 'status' => 'completed',
            'reference_image_path' => $path, 'reference_image_mime' => $mime,
            'reference_updated_by' => $editor, 'reference_updated_at' => $path ? '2026-11-02 10:15:00' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->created['orders'][] = $id;

        return $id;
    }

    public function test_single_images_of_both_modes_move_to_the_new_table_and_the_old_columns_are_dropped(): void
    {
        $this->migration->down();
        $this->assertTrue(Schema::hasColumn('orders', 'reference_image_path'));
        $editor = User::factory()->create(['role' => 'owner'])->id;
        $this->created['users'][] = $editor;

        $live = $this->legacyOrder('live', 'order-references/live-uuid.jpg', 'image/jpeg', $editor);
        $demo = $this->legacyOrder('demo', 'order-references/demo-uuid.png', 'image/png', null);
        $none = $this->legacyOrder('live', null, null, null);

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('orders', 'reference_image_path'));
        $this->assertFalse(Schema::hasColumn('orders', 'reference_image_mime'));
        $rows = DB::table('order_reference_images')->whereIn('order_id', [$live, $demo, $none])->get()->keyBy('order_id');
        $this->assertCount(2, $rows);
        $this->assertSame('order-references/live-uuid.jpg', $rows[$live]->path);
        $this->assertSame('image/jpeg', $rows[$live]->mime);
        $this->assertSame(1, (int) $rows[$live]->position);
        $this->assertSame($editor, (int) $rows[$live]->uploaded_by);
        $this->assertSame('2026-11-02 10:15:00', (string) $rows[$live]->created_at);
        $this->assertNull($rows[$live]->size_bytes);
        $this->assertSame('order-references/demo-uuid.png', $rows[$demo]->path);
        $this->assertNull($rows[$demo]->uploaded_by);
    }

    public function test_rolling_back_restores_the_first_image_of_each_sale(): void
    {
        $this->migration->down();
        $order = $this->legacyOrder('live', 'order-references/first.jpg', 'image/jpeg', null);
        $this->migration->up();
        DB::table('order_reference_images')->insert([
            'order_id' => $order, 'path' => 'order-references/second.png', 'mime' => 'image/png', 'position' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration->down();

        $this->assertFalse(Schema::hasTable('order_reference_images'));
        $row = DB::table('orders')->where('id', $order)->first();
        $this->assertSame('order-references/first.jpg', $row->reference_image_path);
        $this->assertSame('image/jpeg', $row->reference_image_mime);
    }
}
