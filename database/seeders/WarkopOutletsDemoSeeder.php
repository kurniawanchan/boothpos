<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Support\ModeGate;
use Illuminate\Database\Seeder;

/**
 * 045 Stage 8 (T103) — demo multi-outlet OPSIONAL: tiga outlet "Warkop WJM 1/2/3" di atas data Sakana Fridge, pengguna
 * contoh ditugaskan, meja per outlet, saldo stok per outlet, dan satu transfer dalam perjalanan.
 *
 *   php artisan db:seed --class=WarkopOutletsDemoSeeder      (jalankan SETELAH SakanaFridgeDemoSeeder)
 *
 * Sengaja TERPISAH dari SakanaFridgeDemoSeeder (yang tetap satu outlet bawaan, tanpa perubahan): instalasi satu outlet
 * dan tesnya tidak berubah. Idempoten — dijalankan ulang tidak menggandakan apa pun. Semua baris bertanda DEMO.
 */
class WarkopOutletsDemoSeeder extends Seeder
{
    private const MARK = 'Stok demo Warkop WJM (WarkopOutletsDemoSeeder)';

    public function run(): void
    {
        ModeGate::runAs('demo', function () {
            // Lebih dari satu outlet aktif butuh lisensi Master (aturan LicenseGate yang sama dengan layar Outlet).
            Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);

            $outlets = collect([1, 2, 3])->map(fn (int $n) => Outlet::firstOrCreate(
                ['code' => "WJ{$n}"],
                ['name' => "Warkop WJM {$n}", 'is_active' => true],
            ));

            $this->assignUsers($outlets);

            foreach ($outlets as $outlet) {
                foreach (['Meja 1', 'Meja 2', 'Meja 3'] as $name) {
                    DiningTable::withoutGlobalScopes()->firstOrCreate(['outlet_id' => $outlet->id, 'name' => $name], ['area' => 'Indoor', 'seats' => 4, 'is_active' => true]);
                }
            }

            $this->seedStock($outlets);
            $this->seedTransfer($outlets);
        });
    }

    /** kasir01 → WJ1, kasir02 → WJ2, inventory → WJ1 + WJ2 (bila pengguna contoh itu ada). */
    private function assignUsers($outlets): void
    {
        $plan = ['kasir01' => [1], 'kasir02' => [2], 'inventory' => [1, 2]];
        foreach ($plan as $username => $numbers) {
            $user = User::withoutGlobalScopes()->where('username', $username)->first();
            if ($user !== null && ! $user->hasAllOutlets()) {
                $user->outlets()->syncWithoutDetaching($outlets->filter(fn ($o) => in_array((int) substr($o->code, 2), $numbers, true))->pluck('id')->all());
            }
        }
    }

    /** Tiga item pertama: 24 unit di tiap outlet WJ (lewat StockService — buku besar lengkap). */
    private function seedStock($outlets): void
    {
        $stock = app(StockService::class);
        foreach (ProductVariant::query()->orderBy('id')->limit(3)->get() as $variant) {
            foreach ($outlets as $outlet) {
                $already = StockMovement::withoutGlobalScopes()->where(['variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'reason' => self::MARK])->exists();
                if (! $already) {
                    $stock->applyMovement(variant: $variant, outletId: $outlet->id, type: 'initial', qtyChange: 24, reason: self::MARK);
                }
            }
        }
    }

    /** Satu transfer WJ1 → WJ2 dalam perjalanan (dilewati bila sudah ada transfer demo). */
    private function seedTransfer($outlets): void
    {
        if (StockTransfer::withoutGlobalScopes()->where('note', self::MARK)->exists()) {
            return;
        }
        $owner = User::withoutGlobalScopes()->where('username', 'owner')->first() ?? User::withoutGlobalScopes()->orderBy('id')->first();
        $variant = ProductVariant::query()->orderBy('id')->first();
        if ($owner === null || $variant === null) {
            return;
        }
        app(StockTransferService::class)->dispatch($owner, $outlets[0]->id, $outlets[1]->id, [['kind' => 'variant', 'item_id' => $variant->id, 'qty' => 6]], self::MARK);
    }
}
