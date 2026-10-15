<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Station;
use App\Support\ModeGate;
use Illuminate\Database\Seeder;

/**
 * 048 / T003 — menu warkop, meja, stasiun, dan modifier contoh untuk outlet "Warkop WJM 3" (menu yang sama dengan
 * desain design/screens/pos-app-data.js). Dipakai verifikasi aplikasi Android POS.
 *
 *   php artisan db:seed --class=WarkopMenuDemoSeeder      (setelah WarkopPosStaffSeeder)
 *
 * HANYA demo/lokal; idempoten (firstOrCreate per kode/nama). Item dibuat tanpa pelacakan stok (`track_stock=false`):
 * makanan/minuman dibuat saat dipesan, bukan persediaan barang jadi.
 */
class WarkopMenuDemoSeeder extends Seeder
{
    private const CATEGORIES = ['KP' => ['Kopi', 'Bar'], 'NK' => ['Non-kopi', 'Bar'], 'MK' => ['Makanan', 'Dapur'], 'CM' => ['Camilan', 'Dapur'], 'TB' => ['Tambahan', 'Dapur']];

    /** kode => [nama, kategori, harga, [grup modifier], habis?] */
    private const ITEMS = [
        'K1' => ['Kopi Tubruk', 'KP', 8000, []], 'K2' => ['Kopi Susu', 'KP', 12000, []],
        'K3' => ['Es Kopi Susu Aren', 'KP', 18000, ['gula', 'es']], 'K4' => ['Vietnam Drip', 'KP', 15000, []],
        'K5' => ['Kopi Jahe', 'KP', 13000, []], 'K6' => ['Cappuccino', 'KP', 18000, ['suhu']],
        'K7' => ['Americano', 'KP', 15000, ['suhu']], 'K8' => ['Affogato', 'KP', 22000, [], true],
        'N1' => ['Teh Tarik', 'NK', 12000, ['suhu']], 'N2' => ['Es Teh Manis', 'NK', 6000, []],
        'N3' => ['Teh Poci', 'NK', 10000, []], 'N4' => ['Susu Cokelat', 'NK', 14000, ['suhu']],
        'N5' => ['Wedang Jahe', 'NK', 10000, []], 'N6' => ['Es Jeruk', 'NK', 10000, []], 'N7' => ['Lemon Tea', 'NK', 12000, ['suhu']],
        'M1' => ['Indomie Goreng', 'MK', 12000, ['pedas', 'topping']], 'M2' => ['Indomie Rebus', 'MK', 12000, ['pedas', 'topping']],
        'M3' => ['Nasi Goreng Kampung', 'MK', 22000, ['pedas']], 'M4' => ['Nasi Telur Ceplok', 'MK', 15000, []],
        'M5' => ['Nasi Ayam Geprek', 'MK', 25000, ['pedas']], 'M6' => ['Mie Tek-tek', 'MK', 20000, ['pedas']], 'M7' => ['Roti Bakar', 'MK', 15000, ['rasa']],
        'S1' => ['Pisang Goreng', 'CM', 12000, [], true], 'S2' => ['Kentang Goreng', 'CM', 15000, []], 'S3' => ['Tahu Crispy', 'CM', 10000, []],
        'S4' => ['Cireng Rujak', 'CM', 12000, []], 'S5' => ['Tempe Mendoan', 'CM', 10000, []], 'S6' => ['Singkong Keju', 'CM', 12000, []],
        'E1' => ['Telur Ceplok', 'TB', 4000, []], 'E2' => ['Nasi Putih', 'TB', 5000, []], 'E3' => ['Kerupuk', 'TB', 2000, []], 'E4' => ['Air Mineral', 'TB', 5000, []],
    ];

    /** kunci => [nama, min, max, [[opsi, tambahan harga]]] */
    private const GROUPS = [
        'gula' => ['Level gula', 1, 1, [['Normal', 0], ['Kurang manis', 0], ['Tanpa gula', 0]]],
        'es' => ['Es', 1, 1, [['Es normal', 0], ['Sedikit es', 0], ['Tanpa es', 0]]],
        'suhu' => ['Suhu', 1, 1, [['Panas', 0], ['Dingin', 2000]]],
        'pedas' => ['Level pedas', 1, 1, [['Tidak pedas', 0], ['Sedang', 0], ['Pedas', 0], ['Pedas banget', 0]]],
        'topping' => ['Topping', 0, 2, [['Telur', 4000], ['Kornet', 5000], ['Keju', 4000], ['Sosis', 5000]]],
        'rasa' => ['Rasa', 1, 2, [['Cokelat', 0], ['Keju', 0], ['Srikaya', 0], ['Kacang', 0]]],
    ];

    public function run(): void
    {
        ModeGate::runAs('demo', function () {
            $outlet = Outlet::firstOrCreate(['code' => 'WJ3'], ['name' => 'Warkop WJM 3', 'is_active' => true]);

            $stations = [];
            foreach (['Bar', 'Dapur'] as $i => $name) {
                $stations[$name] = Station::withoutGlobalScopes()->firstOrCreate(['outlet_id' => $outlet->id, 'name' => $name], ['display_order' => $i]);
            }

            $categories = [];
            foreach (self::CATEGORIES as $code => [$name, $station]) {
                $categories[$code] = Category::firstOrCreate(['code' => $code], ['name' => $name, 'station_id' => $stations[$station]->id, 'is_active' => true]);
            }

            $groups = [];
            foreach (self::GROUPS as $key => [$name, $min, $max, $options]) {
                $group = ModifierGroup::firstOrCreate(['name' => $name], [
                    'selection_type' => $max === 1 ? ModifierGroup::SINGLE : ModifierGroup::MULTIPLE,
                    'is_required' => $min > 0, 'min_select' => $min, 'max_select' => $max,
                ]);
                foreach ($options as $order => [$optName, $delta]) {
                    ModifierOption::firstOrCreate(['modifier_group_id' => $group->id, 'name' => $optName], ['price_delta' => $delta, 'display_order' => $order]);
                }
                $groups[$key] = $group;
            }

            foreach (self::ITEMS as $code => $row) {
                [$name, $cat, $price, $groupKeys] = $row;
                $product = Product::firstOrCreate(['code_prefix' => "WJM{$code}"], [
                    'home_outlet_id' => $outlet->id, 'category_id' => $categories[$cat]->id, 'product_segment' => 'WJM', 'name' => $name, 'is_active' => true,
                ]);
                ProductVariant::firstOrCreate(['sku' => "WJM{$code}-001"], [
                    'product_id' => $product->id, 'variant_name' => 'Standard', 'sell_price' => $price, 'cost_price' => (int) ($price * 0.4),
                    'current_stock' => 0, 'track_stock' => false,
                ]);
                $product->modifierGroups()->syncWithoutDetaching(array_map(fn ($k) => $groups[$k]->id, $groupKeys));
            }

            // Outlet ini hanya menjual menu warkop: produk contoh merchandise (Sakana Fridge) tidak dibawa di sini.
            Product::query()->where('home_outlet_id', '!=', $outlet->id)->get()->each(
                fn (Product $p) => \App\Models\OutletItemSetting::updateOrCreate(['outlet_id' => $outlet->id, 'product_id' => $p->id], ['is_carried' => false]),
            );

            foreach (['Indoor' => ['1', '2', '3', '4', '5', '6', '7', '8'], 'Teras' => ['T1', 'T2', 'T3', 'T4', 'T5', 'T6']] as $area => $names) {
                foreach ($names as $order => $name) {
                    DiningTable::withoutGlobalScopes()->firstOrCreate(['outlet_id' => $outlet->id, 'area' => $area, 'name' => $name], ['seats' => 4, 'display_order' => $order, 'is_active' => true]);
                }
            }
        });
    }
}
