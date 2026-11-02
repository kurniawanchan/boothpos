<?php

namespace App\Services;

use App\Models\Material;
use App\Models\User;
use App\Support\OutletContext;
use Illuminate\Validation\ValidationException;

/**
 * 088 US2 (keputusan pemilik Q2 = B, research D8) — saran pembelian Laporan Bahan → draf PO, SATU per vendor rujukan
 * bahan (harga vendor pilihan, lalu termurah — aturan yang sama dengan biaya satuan dan saran). Semua PO dibuat lewat
 * PurchaseOrderService::create (satu-satunya jalur tulis PO; status draf, log aktivitas, stok baru bergerak saat
 * diterima). Outlet = outlet TUNGGAL yang berlaku dari server, tidak pernah dari body (Konstitusi IV, aturan 045).
 * Baris yang tidak bisa dibeli ditolak PER BARIS dengan alasan dan tidak menggagalkan vendor lain.
 */
class MaterialPurchaseDrafts
{
    public function __construct(
        private PurchaseOrderService $orders,
        private MaterialReport $report,
        private OutletContext $outlets,
    ) {}

    /**
     * @param  list<array{material_id: int, qty: float|int|string}>  $lines
     * @return array{created: list<array<string, mixed>>, refused: list<array{material_id: int, reason: string}>}
     *
     * @throws ValidationException tanpa outlet tunggal
     */
    public function create(array $lines, User $by): array
    {
        $outletId = $this->outlets->singleId();
        if ($outletId === null) {
            throw ValidationException::withMessages(['outlet' => [__('reports.materials_outlet_required')]]);
        }

        $ids = collect($lines)->pluck('material_id')->map(fn ($id) => (int) $id)->unique();
        // Lewat model: mode data aktif berlaku; bahan dari mode lain / tak ada = "tidak terlihat".
        $materials = Material::query()->whereIn('id', $ids)->get()->keyBy('id');
        $reference = $this->report->referencePrices($materials->keys());

        $refused = [];
        $byVendor = [];
        foreach ($lines as $line) {
            $id = (int) $line['material_id'];
            $m = $materials->get($id);
            $price = $reference->get($id);
            $reason = match (true) {
                $m === null => 'not_visible',
                ! $m->is_active => 'inactive_material',
                $price === null => 'no_vendor',
                default => null,
            };
            if ($reason !== null) {
                $refused[] = ['material_id' => $id, 'reason' => $reason];

                continue;
            }
            $byVendor[$price->vendor_id]['vendor'] = $price->vendor?->name;
            $byVendor[$price->vendor_id]['items'][] = [
                'line_type' => 'material', 'material_id' => $id,
                'qty' => (float) $line['qty'], 'unit_price' => (float) $price->price,
            ];
        }

        $created = [];
        foreach ($byVendor as $vendorId => $group) {
            $po = $this->orders->create([
                'vendor_id' => $vendorId, 'outlet_id' => $outletId,
                'notes' => __('reports.materials_draft_note'), 'items' => $group['items'],
            ], $by);
            $created[] = [
                'purchase_order_id' => $po->id, 'po_number' => $po->po_number, 'vendor_id' => (int) $vendorId, 'vendor' => $group['vendor'],
                'line_count' => count($group['items']), 'total' => number_format((float) $po->total_amount, 2, '.', ''),
            ];
        }

        return ['created' => $created, 'refused' => $refused];
    }
}
