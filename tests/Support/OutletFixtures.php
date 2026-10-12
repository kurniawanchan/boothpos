<?php

namespace Tests\Support;

use App\Models\CashierSession;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * 045 (T006) — fixture bersama untuk semua tes outlet. Dipakai di setiap tahap (Stage 1–8).
 *
 * Dua sifat penting:
 *  1. Bekerja SEBELUM maupun SESUDAH Stage 1 (rename artists → outlets): kelas model dipilih lewat `outletModel()`.
 *  2. Pembantu yang butuh skema tahap lanjut (penugasan pengguna, kolom outlet_id, relasi menu, saldo per outlet)
 *     melempar LogicException dengan nama tahapnya bila skemanya belum ada, bukan gagal diam-diam di tengah tes.
 *
 * Konvensi data: kode outlet 3 huruf `WJ1`, `WJ2`, … dan nama "Warkop WJM 1", "Warkop WJM 2", … .
 */
trait OutletFixtures
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    protected function outletModel(): string
    {
        return class_exists(\App\Models\Outlet::class) ? \App\Models\Outlet::class : \App\Models\Outlet::class;
    }

    /** @return Collection<int, \Illuminate\Database\Eloquent\Model> */
    protected function makeOutlets(int $count = 3): Collection
    {
        $model = $this->outletModel();

        return collect(range(1, $count))->map(fn (int $i) => $model::factory()->create([
            'code' => 'WJ'.$i,
            'name' => 'Warkop WJM '.$i,
        ]));
    }

    private function needsStage(string $stage, bool $present): void
    {
        if (! $present) {
            throw new LogicException("Pembantu fixture ini butuh skema {$stage} yang belum ada (lihat specs/045-outlets-per-outlet-menu/tasks.md).");
        }
    }

    /** Stage 2: tugaskan pengguna ke outlet (administrator tidak butuh baris). */
    protected function assignUser(User $user, iterable $outlets): User
    {
        $this->needsStage('Stage 2 (tabel outlet_user)', Schema::hasTable('outlet_user'));
        $ids = collect($outlets)->map(fn ($o) => is_object($o) ? $o->id : (int) $o)->all();
        DB::table('outlet_user')->where('user_id', $user->id)->delete();
        DB::table('outlet_user')->insert(collect($ids)->map(fn ($id) => ['user_id' => $user->id, 'outlet_id' => $id])->all());

        return $user;
    }

    /** Shift terbuka milik kasir. Sebelum Stage 2 outlet diabaikan; sesudahnya menjadi outlet shift. */
    protected function shiftAt($outlet, User $cashier, array $over = []): CashierSession
    {
        $attrs = array_merge([
            'user_id' => $cashier->id,
            'status' => 'open',
        ], $over);
        if (Schema::hasColumn('cashier_sessions', 'outlet_id')) {
            $attrs['outlet_id'] = is_object($outlet) ? $outlet->id : $outlet;
        }

        return CashierSession::factory()->create($attrs);
    }

    /** Order selesai sederhana pada shift tertentu (Stage 2: outlet diwariskan dari shift). */
    protected function orderAt(CashierSession $shift, ProductVariant $variant, int $qty = 1, array $over = []): Order
    {
        // Stage 5: stok per outlet — pastikan saldo di outlet SHIFT cukup (fixture; bukan jalur produksi).
        if (Schema::hasTable('variant_outlet_stock') && $variant->track_stock !== false && $shift->outlet_id) {
            $have = (int) DB::table('variant_outlet_stock')->where(['variant_id' => $variant->id, 'outlet_id' => $shift->outlet_id])->value('current_stock');
            if ($have < $qty) {
                $this->stockAt($variant, $shift->outlet_id, $have + $qty + 50);
            }
        }
        $service = app(\App\Services\OrderService::class);
        $total = (float) $variant->sell_price * $qty;

        return $service->create(array_merge([
            'session_id' => $shift->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $over), User::findOrFail($shift->user_id));
    }

    /** Stage 2: meja milik outlet. */
    protected function tableAt($outlet, string $name, array $over = []): DiningTable
    {
        $this->needsStage('Stage 2 (dining_tables.outlet_id)', Schema::hasColumn('dining_tables', 'outlet_id'));

        return DiningTable::factory()->create(array_merge(['name' => $name, 'outlet_id' => is_object($outlet) ? $outlet->id : $outlet], $over));
    }

    /** Stage 3: set outlet tempat produk dibawa (sisanya tidak dibawa). */
    protected function carry(Product $product, iterable $outlets): void
    {
        $this->needsStage('Stage 3 (outlet_item_settings)', Schema::hasTable('outlet_item_settings'));
        $carried = collect($outlets)->map(fn ($o) => is_object($o) ? $o->id : (int) $o)->all();
        $all = DB::table('outlets')->pluck('id');
        foreach ($all as $id) {
            DB::table('outlet_item_settings')->updateOrInsert(
                ['outlet_id' => $id, 'product_id' => $product->id],
                ['is_carried' => in_array($id, $carried, true), 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /** Stage 5: saldo stok varian di satu outlet (lewat StockService, bukan menulis langsung). */
    protected function stockAt(ProductVariant $variant, $outlet, int $qty): void
    {
        $this->needsStage('Stage 5 (variant_outlet_stock)', Schema::hasTable('variant_outlet_stock'));
        $outletId = is_object($outlet) ? $outlet->id : $outlet;
        $current = (int) DB::table('variant_outlet_stock')->where(['variant_id' => $variant->id, 'outlet_id' => $outletId])->value('current_stock');
        $delta = $qty - $current;
        if ($delta !== 0) {
            app(\App\Services\StockService::class)->applyMovement($variant, (int) $outletId, 'adjustment', $delta, null, null, 'fixture', null);
        }
    }
}
