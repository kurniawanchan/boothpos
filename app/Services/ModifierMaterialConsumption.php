<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialStockMovement;
use App\Models\ModifierOptionMaterial;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\MaterialWarningBag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 087 — SATU-SATUNYA penerjemah "baris pesanan + pilihan modifier yang dipesan" menjadi pergerakan stok bahan.
 * Semua tulis tetap lewat MaterialStockService::applyMovement() (satu penulis stok bahan, konstitusi I).
 *
 * Dipanggil hanya dari:
 *  - titik pesanan MENJADI SELESAI → consumeOrder (OrderService::checkout, TabSettlementService::settle/split).
 *    Keputusan pemilik 2026-10-31 (FR-015): bahan dipotong saat selesai, bukan saat item masuk tab — jadi
 *    tambah/kurangi/ganti pilihan selama tab terbuka tidak menyentuh bahan (research D4);
 *  - void/refund pesanan selesai → restoreOrder, yang membaca BUKU BESAR, bukan pemetaan: pemetaan bisa diubah
 *    setelah jual, sedangkan yang harus kembali adalah yang dulu benar-benar dipotong (research D7);
 *  - batal "buang" di tab terbuka → wasteLine (research D8).
 *
 * Kunci selalu diambil berurutan material_id lalu order_item_id: dua pesanan bersamaan yang memakai bahan
 * yang sama mengunci dalam urutan yang sama sehingga tidak saling menunggu (deadlock) (research D5).
 */
class ModifierMaterialConsumption
{
    public function __construct(
        private MaterialStockService $stock,
        private RecipeService $recipes,
        private MaterialWarningBag $warnings,
    ) {}

    /**
     * Potong bahan semua baris pesanan yang baru selesai, tambahkan biaya bahan ke `cost_price` baris, dan
     * kumpulkan peringatan untuk bahan yang menjadi minus.
     *
     * @return array<int, array<string, mixed>> peringatan (juga dimasukkan ke MaterialWarningBag)
     */
    public function consumeOrder(Order $order, User $user): array
    {
        $items = $order->items()->get();
        if ($items->isEmpty()) {
            return [];
        }

        // Sabuk pengaman: jalur retry (local_ref, client_ref, submission_ref) sudah mencegah pemanggilan kedua;
        // bila tetap terjadi, buku besar membuktikan pesanan ini sudah dipotong — jangan potong lagi.
        $already = MaterialStockMovement::query()
            ->where('type', 'sale')->where('reference_type', 'order_item')
            ->whereIn('reference_id', $items->pluck('id'))->exists();
        if ($already) {
            Log::warning('087: consumeOrder dipanggil ulang untuk pesanan yang bahannya sudah dipotong.', ['order_id' => $order->id]);

            return [];
        }

        [$need, $materials, $costPerPortion] = $this->needFor($items);

        $warnings = [];
        foreach ($need as $materialId => $perLine) {
            $first = null;
            $last = null;
            $required = 0.0;
            foreach ($perLine as $lineId => $qty) {
                $movement = $this->stock->applyMovement(material: $materials[$materialId], outletId: (int) $order->outlet_id, type: 'sale', qtyChange: -$qty, referenceType: 'order_item', referenceId: $lineId, userId: $user->id);
                $first ??= (float) $movement->stock_before;
                $last = (float) $movement->stock_after;
                $required += $qty;
            }

            if ($last !== null && $last < -0.0005) {
                $warnings[] = [
                    'material_id' => $materialId,
                    'material' => $materials[$materialId]->name,
                    'unit' => $materials[$materialId]->unit,
                    'outlet_id' => (int) $order->outlet_id,
                    // 2026-10-31: nama outlet ikut, karena saldo bahan per outlet — tanpanya "stok 0" tampak salah
                    // bagi kasir yang melihat stok outlet lain di daftar bahan.
                    'outlet' => \App\Models\Outlet::withTrashed()->whereKey($order->outlet_id)->value('name'),
                    'stock_before' => number_format($first, 3, '.', ''),
                    'required' => number_format($required, 3, '.', ''),
                    'stock_after' => number_format($last, 3, '.', ''),
                ];
            }
        }

        $this->addMaterialCost($order, $items, $costPerPortion);

        foreach ($warnings as $warning) {
            $this->warnings->push($warning);
        }

        return $warnings;
    }

    /** Kembalikan potongan bersih (sale − return) tiap (baris, bahan) pesanan ini. 'waste' tidak pernah kembali. */
    public function restoreOrder(Order $order, User $user): void
    {
        $lineIds = $order->items()->pluck('id');
        if ($lineIds->isEmpty()) {
            return;
        }

        $rows = MaterialStockMovement::query()
            ->whereIn('type', ['sale', 'return'])->where('reference_type', 'order_item')
            ->whereIn('reference_id', $lineIds)
            ->groupBy('material_id', 'reference_id')
            ->selectRaw('material_id, reference_id, SUM(qty_change) AS net')
            ->orderBy('material_id')->orderBy('reference_id')
            ->get();

        $materials = Material::withTrashed()->whereIn('id', $rows->pluck('material_id')->unique())->get()->keyBy('id');

        foreach ($rows as $row) {
            $back = round(-(float) $row->net, 3);
            if ($back > 0.0005 && $materials->has($row->material_id)) {
                $this->stock->applyMovement(material: $materials[$row->material_id], outletId: (int) $order->outlet_id, type: 'return', qtyChange: $back, referenceType: 'order_item', referenceId: (int) $row->reference_id, userId: $user->id);
            }
        }
    }

    /** Baris yang sudah dimasak lalu dibuang: bahannya terpakai walau pesanan tak pernah selesai. */
    public function wasteLine(OrderItem $line, User $user): void
    {
        [$need, $materials] = $this->needFor(collect([$line]));
        $outletId = (int) $line->order()->value('outlet_id');

        foreach ($need as $materialId => $perLine) {
            foreach ($perLine as $lineId => $qty) {
                $this->stock->applyMovement(material: $materials[$materialId], outletId: $outletId, type: 'waste', qtyChange: -$qty, referenceType: 'order_item', referenceId: $lineId, userId: $user->id);
            }
        }
    }

    /**
     * Kebutuhan bahan per baris, dari snapshot `order_items.modifiers` + pemetaan saat ini (bahan aktif saja).
     * Satu query pemetaan untuk seluruh pesanan (konstitusi V).
     *
     * @param  Collection<int, OrderItem>  $items
     * @return array{0: array<int, array<int, float>>, 1: array<int, Material>, 2: array<int, float>}
     *         [need[material_id][order_item_id] => qty, materials by id, biaya bahan per porsi per baris]
     */
    private function needFor(Collection $items): array
    {
        $optionIds = $items->flatMap(fn (OrderItem $i) => collect($i->modifiers ?? [])->pluck('option_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($optionIds->isEmpty()) {
            return [[], [], []];
        }

        // Bahan nonaktif/terhapus dilewati: pemetaan lama tidak boleh terus memotong bahan yang sudah tak dipakai.
        $mappings = ModifierOptionMaterial::query()
            ->whereIn('modifier_option_id', $optionIds)
            ->whereIn('material_id', Material::query()->where('is_active', true)->select('id'))
            ->get()->groupBy('modifier_option_id');
        if ($mappings->isEmpty()) {
            return [[], [], []];
        }

        $materials = Material::query()->whereIn('id', $mappings->flatten()->pluck('material_id')->unique())->get()->keyBy('id');
        $unitCost = $materials->map(fn (Material $m) => (float) ($this->recipes->unitCost($m) ?? 0));

        $need = [];
        $costPerPortion = [];
        foreach ($items as $item) {
            foreach (collect($item->modifiers ?? [])->pluck('option_id')->filter()->unique() as $optionId) {
                foreach ($mappings->get((int) $optionId, collect()) as $m) {
                    $need[$m->material_id][$item->id] = ($need[$m->material_id][$item->id] ?? 0) + (float) $m->quantity * $item->qty;
                    $costPerPortion[$item->id] = ($costPerPortion[$item->id] ?? 0) + (float) $m->quantity * $unitCost[$m->material_id];
                }
            }
        }

        ksort($need);
        foreach ($need as $materialId => $perLine) {
            ksort($perLine);
            $need[$materialId] = array_filter(array_map(fn ($q) => round($q, 3), $perLine), fn ($q) => $q > 0);
        }

        return [array_filter($need), $materials->all(), $costPerPortion];
    }

    /**
     * 087 FR-023 — biaya bahan pilihan masuk HPP baris SEKALI, saat selesai (snapshot finansial, konstitusi IV).
     * Hanya `total_cost` yang ditulis ulang: memanggil recalculate penuh di checkout berisiko mengubah
     * subtotal/pajak yang sudah ditagih (research D9).
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  array<int, float>  $costPerPortion
     */
    private function addMaterialCost(Order $order, Collection $items, array $costPerPortion): void
    {
        $changed = false;
        foreach ($items as $item) {
            $extra = round($costPerPortion[$item->id] ?? 0, 2);
            if ($extra > 0) {
                $item->forceFill(['cost_price' => round((float) $item->cost_price + $extra, 2)])->save();
                $changed = true;
            }
        }

        if ($changed) {
            $order->forceFill(['total_cost' => round((float) $items->sum(fn (OrderItem $i) => (float) $i->cost_price * $i->qty), 2)])->save();
        }
    }
}
