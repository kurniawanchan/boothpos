<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBomLineRequest;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\StoreVendorMaterialPriceRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Http\Requests\UpdateVendorMaterialPriceRequest;
use App\Http\Resources\BomLineResource;
use App\Http\Resources\MaterialResource;
use App\Http\Resources\VendorMaterialPriceResource;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\VendorMaterialPrice;
use App\Services\ActivityLogger;
use App\Exceptions\BomRuleException;
use App\Services\BomCostCalculator;
use App\Services\VariantBomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesStockOutlet;

    public function __construct(private ActivityLogger $activityLogger, private VariantBomService $bomService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Material::class);
        // 087 — `stock_status`: negative = minus di salah satu outlet yang berlaku (penjualan boleh membuat minus);
        // available = ada saldo > 0; out = tidak ada saldo > 0 (kosong atau minus). Semuanya per outlet yang terlihat.
        $request->validate(['stock_status' => ['sometimes', 'nullable', 'in:negative,available,out']]);
        $visible = fn ($b, string $op) => \App\Support\OutletFilter::apply($b, 'outlet_id')->where('current_stock', $op, 0);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $materials = Material::query()
            ->withCount('vendorPrices')
            ->with(['outletStock' => fn ($q) => \App\Support\OutletFilter::apply($q, 'outlet_id')->with('outlet:id,code,name')->orderBy('outlet_id')])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->input('stock_status') === 'negative', fn ($q) => $q->whereHas('outletStock', fn ($b) => $visible($b, '<')))
            ->when($request->input('stock_status') === 'available', fn ($q) => $q->whereHas('outletStock', fn ($b) => $visible($b, '>')))
            ->when($request->input('stock_status') === 'out', fn ($q) => $q->whereDoesntHave('outletStock', fn ($b) => $visible($b, '>')))
            // 043 (Q2 = B) — tab jenis bahan di layar Produk.
            ->when($request->filled('kind') && in_array($request->string('kind')->value(), ['ingredient', 'semi_finished', 'packaging'], true), fn ($q) => $q->where('kind', $request->string('kind')->value()))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => MaterialResource::collection($materials->items()),
            'meta' => [
                'current_page' => $materials->currentPage(),
                'per_page' => $materials->perPage(),
                'total' => $materials->total(),
                'last_page' => $materials->lastPage(),
            ],
        ]);
    }

    public function store(StoreMaterialRequest $request): JsonResponse
    {
        $material = Material::create($request->validated());

        DB::transaction(function () use ($material, $request) {
            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'created',
                entityType: 'Material',
                entityId: $material->id,
                description: "Menambah bahan {$material->code} ({$material->name}).",
                newValues: $material->only($material->getFillable()),
            );
        });

        return response()->json(new MaterialResource($material), 201);
    }

    public function show(Material $material): JsonResponse
    {
        $this->authorize('view', $material);

        $material->loadCount('vendorPrices')->load(['vendorPrices.vendor', 'modifierUsages.option.group']);

        return response()->json(new MaterialResource($material));
    }

    public function update(UpdateMaterialRequest $request, Material $material): JsonResponse
    {
        $material->update($request->validated());

        return response()->json(new MaterialResource($material->fresh()));
    }

    public function destroy(Request $request, Material $material): JsonResponse
    {
        $this->authorize('delete', $material);

        // Bahan yang masih dipakai baris harga vendor ATAU baris BOM tidak
        // boleh dihapus diam-diam — keduanya akan jadi yatim dan diam-diam
        // mengubah bom_cost varian yang memakainya.
        if ($material->vendorPrices()->exists()) {
            return response()->json([
                'message' => __('vendors_materials.material_delete_has_vendor_prices'),
            ], 409);
        }

        if ($material->bomLines()->exists()) {
            return response()->json([
                'message' => __('vendors_materials.material_delete_used_in_bom'),
            ], 409);
        }

        // 087 — bahan memakai soft delete, jadi FK RESTRICT dari pemetaan pilihan modifier tidak pernah terpicu;
        // tanpa penjaga ini pemetaan aktif akan diam-diam berhenti memotong bahan (research D10).
        $usage = $material->modifierUsages()->whereHas('option', fn ($q) => $q->where('is_active', true))
            ->with('option.group')->first();
        if ($usage !== null) {
            return response()->json([
                'message' => __('vendors_materials.material_delete_used_by_modifier', ['usage' => ($usage->option->group?->name ?? '?').' → '.$usage->option->name]),
            ], 409);
        }

        DB::transaction(function () use ($material, $request) {
            $snapshot = $material->only($material->getFillable());

            $material->delete();

            $this->activityLogger->log(
                userId: $request->user()?->id,
                action: 'deleted',
                entityType: 'Material',
                entityId: $material->id,
                description: "Menghapus bahan {$material->code} ({$material->name}).",
                oldValues: $snapshot,
            );
        });

        return response()->json(null, 204);
    }

    // =====================================================================
    // Harga vendor per bahan (vendor mana saja + berapa harganya)
    // =====================================================================

    public function storeVendorPrice(StoreVendorMaterialPriceRequest $request, Material $material): JsonResponse
    {
        $price = DB::transaction(function () use ($request, $material) {
            if ($request->boolean('is_preferred')) {
                // Hanya satu vendor preferred per bahan — ditegakkan di sini,
                // bukan constraint DB, karena "preferred" adalah pilihan
                // bisnis (bisa berubah kapan saja), bukan invariant struktural.
                $material->vendorPrices()->update(['is_preferred' => false]);
            }

            return $material->vendorPrices()->create($request->validated());
        });

        return response()->json(new VendorMaterialPriceResource($price->load('vendor')), 201);
    }

    public function updateVendorPrice(UpdateVendorMaterialPriceRequest $request, VendorMaterialPrice $vendorPrice): JsonResponse
    {
        DB::transaction(function () use ($request, $vendorPrice) {
            if ($request->boolean('is_preferred')) {
                VendorMaterialPrice::where('material_id', $vendorPrice->material_id)
                    ->where('id', '!=', $vendorPrice->id)
                    ->update(['is_preferred' => false]);
            }

            $vendorPrice->update($request->validated());
        });

        return response()->json(new VendorMaterialPriceResource($vendorPrice->fresh()->load('vendor')));
    }

    public function destroyVendorPrice(Request $request, VendorMaterialPrice $vendorPrice): JsonResponse
    {
        if (! $request->user()->canAccessMenu('materials')) {
            return response()->json(['message' => __('vendors_materials.not_authorized_vendor_prices')], 403);
        }

        $vendorPrice->delete();

        return response()->json(null, 204);
    }

    // =====================================================================
    // BOM per varian produk — jalur LEGACY (bahan + jumlah, tanpa baris PO).
    // Baca/ubah/hapus baris kini ada di VariantBomController (034-seller-po-bom);
    // yang tersisa di sini hanya pembuatan baris legacy dan rincian biaya.
    // =====================================================================

    public function storeBomLine(StoreBomLineRequest $request, ProductVariant $variant): JsonResponse
    {
        // 034-seller-po-bom — jalur LEGACY tetap ada, tetapi lewat
        // VariantBomService (audit + larangan pada BOM yang sudah selesai).
        try {
            $line = $this->bomService->addLegacyLine(
                $variant,
                (int) $request->validated('material_id'),
                $request->validated('qty_needed'),
                $request->validated('notes'),
                $request->user(),
            );
        } catch (BomRuleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(new BomLineResource($line->load('material')), 201);
    }




    /**
     * F-baru: rincian modal bahan per varian (BOM lines + harga satuan +
     * biaya per baris + total bom_cost). Read-only, tidak menyentuh
     * cost_price — lihat dokblok BomCostCalculator.
     */
    public function costBreakdown(Request $request, ProductVariant $variant, BomCostCalculator $calculator): JsonResponse
    {
        if (! $request->user()->canAccessMenu('products')) {
            return response()->json(['message' => __('vendors_materials.not_authorized_cost_breakdown')], 403);
        }

        $breakdown = $calculator->breakdown($variant);

        return response()->json([
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'cost_price' => number_format((float) $variant->cost_price, 2, '.', ''),
            'bom_cost' => $breakdown['bom_cost'],
            'material_cost' => $breakdown['material_cost'],
            'service_cost' => $breakdown['service_cost'],
            'has_legacy' => $breakdown['has_legacy'],
            'lines' => $breakdown['lines'],
        ]);
    }

    /**
     * 045 Stage 5 — POST /materials/{material}/adjustments: koreksi stok bahan di SATU outlet (selisih, bukan jumlah
     * akhir). Outlet wajib bila pengguna melihat beberapa; tidak boleh membuat saldo outlet negatif (409/422 dari
     * MaterialStockService). Lewat jalur tunggal MaterialStockService, dengan jejak audit.
     */
    /**
     * 087 (US5) — buku besar bahan, terbaru dulu, dibatasi outlet yang berlaku (OutletFilter). Gerbang sama dengan
     * koreksi stok bahan. Rujukan `order_item` diterjemahkan ke nomor pesanan dengan SATU query per halaman;
     * baris yang sudah dihapus (batal "buang") tetap tampil dengan nomor pesanan null.
     */
    public function movements(Request $request, Material $material): JsonResponse
    {
        $this->authorize('update', $material);

        return $this->ledger($request, $material);
    }

    /**
     * 2026-10-31 (permintaan pemilik: koreksi stok bahan masuk log Inventori → Stok) — buku besar SEMUA bahan, bentuk
     * baris sama dengan per bahan plus nama/kode/satuan bahan. Gerbang: menu Bahan, Stok, atau `pos` (2026-11-01:
     * layar "Stok Bahan" tablet, hanya baca; baris dibatasi outlet yang berlaku dan tidak membawa angka biaya).
     */
    public function allMovements(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessMenu('materials') && ! $user->canAccessMenu('stock') && ! $user->canAccessMenu('pos')) {
            return response()->json(['message' => __('stock.not_authorized')], 403);
        }

        return $this->ledger($request, null);
    }

    private function ledger(Request $request, ?Material $material): JsonResponse
    {
        $types = ['purchase', 'adjustment', 'transfer_out', 'transfer_in', 'transfer_cancel', 'sale', 'return', 'waste'];
        $data = $request->validate([
            'type' => ['sometimes', 'array'],
            'type.*' => ['string', 'in:'.implode(',', $types)],
            'outlet_id' => ['sometimes', 'array'],
            'outlet_id.*' => ['integer'],
            'material_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $base = $material ? $material->stockMovements()->getQuery() : \App\Models\MaterialStockMovement::query();
        $page = \App\Support\OutletFilter::apply($base, 'outlet_id')
            ->with(['user:id,name', 'outlet:id,code', ...($material ? [] : ['material' => fn ($q) => $q->withTrashed()->select('id', 'code', 'name', 'unit')])])
            ->when($data['type'] ?? null, fn ($q, $t) => $q->whereIn('type', $t))
            ->when($data['outlet_id'] ?? null, fn ($q, $o) => $q->whereIn('outlet_id', $o))
            ->when(! $material && isset($data['material_id']), fn ($q) => $q->where('material_id', $data['material_id']))
            // Tanggal BISNIS toko (zona + jam tutup buku), bukan tanggal UTC — sama dengan laporan lain (042).
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', \App\Support\BusinessDay::bounds(\Carbon\Carbon::parse($d)->toDateString())[0]))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', \App\Support\BusinessDay::bounds(\Carbon\Carbon::parse($d)->toDateString())[1]))
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        $lineIds = collect($page->items())->where('reference_type', 'order_item')->pluck('reference_id')->filter()->unique();
        // Id baris berasal dari pergerakan yang SUDAH disaring outlet, jadi gabungan ini tidak membuka outlet lain.
        $orders = $lineIds->isEmpty() ? collect() : DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.id', $lineIds)
            ->get(['order_items.id', 'orders.id as order_id', 'orders.order_number'])->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(fn ($m) => [
                'id' => $m->id,
                ...($material ? [] : ['material_id' => $m->material_id, 'material_code' => $m->material?->code, 'material_name' => $m->material?->name, 'unit' => $m->material?->unit]),
                'type' => $m->type,
                'outlet_id' => $m->outlet_id,
                'outlet_code' => $m->outlet?->code,
                'qty_change' => number_format((float) $m->qty_change, 3, '.', ''),
                'stock_before' => number_format((float) $m->stock_before, 3, '.', ''),
                'stock_after' => number_format((float) $m->stock_after, 3, '.', ''),
                'reference' => $m->reference_type === null ? null : [
                    'type' => $m->reference_type,
                    'id' => $m->reference_id,
                    'order_id' => $m->reference_type === 'order_item' ? ($orders[$m->reference_id]->order_id ?? null) : null,
                    'order_number' => $m->reference_type === 'order_item' ? ($orders[$m->reference_id]->order_number ?? null) : null,
                ],
                'reason' => $m->reason,
                'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name] : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function adjustStock(Request $request, Material $material, \App\Services\MaterialStockService $stock): JsonResponse
    {
        $this->authorize('update', $material);

        $data = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'qty_change' => ['required', 'numeric', 'not_in:0', 'between:-999999999,999999999'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $outletId = $this->stockOutlet($request, $data['outlet_id'] ?? null);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $movement = DB::transaction(function () use ($stock, $material, $outletId, $data, $request) {
            $movement = $stock->applyMovement(
                material: $material, outletId: $outletId, type: 'adjustment', qtyChange: (float) $data['qty_change'],
                referenceType: 'manual_adjustment', userId: $request->user()->id, reason: $data['reason'],
            );
            app(ActivityLogger::class)->log(
                userId: $request->user()->id, action: 'material_stock_adjusted', entityType: 'Material', entityId: $material->id,
                description: "Penyesuaian stok bahan {$material->name} sebanyak {$data['qty_change']} di outlet #{$outletId}. Alasan: {$data['reason']}.",
                oldValues: ['stock' => (float) $movement->stock_before], newValues: ['stock' => (float) $movement->stock_after],
            );

            return $movement;
        });

        return response()->json([
            'material_id' => $material->id, 'outlet_id' => $outletId,
            'stock_before' => number_format((float) $movement->stock_before, 3, '.', ''),
            'stock_after' => number_format((float) $movement->stock_after, 3, '.', ''),
        ], 201);
    }
}
