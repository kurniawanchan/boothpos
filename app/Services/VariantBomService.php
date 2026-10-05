<?php

namespace App\Services;

use App\Exceptions\BomRuleException;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\ReportSplit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 034-seller-po-bom — SATU-SATUNYA penulis BOM varian.
 *
 * Semua perubahan BOM (tambah baris PO, ubah jumlah, hapus, ganti sumber,
 * salin, tandai selesai, buka kembali) lewat service ini, supaya tiga hal
 * selalu terjadi bersama dan dalam SATU transaksi database: (1) perubahan
 * barisnya, (2) sinkron cost_price varian bila BOM-nya sudah SELESAI, dan
 * (3) baris activity_logs. Jalur lain yang menulis product_variant_bom_lines
 * atau cost_price varian secara langsung adalah cacat (Constitution I) —
 * kecuali jalur cost_price manual untuk varian yang BOM-nya BELUM selesai.
 *
 * Biaya baris SELALU berasal dari baris purchase order di server (snapshot
 * unit_cost), tidak pernah dari input klien.
 */
class VariantBomService
{
    public function __construct(
        private BomCostCalculator $calculator,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Snapshot yang disalin dari baris PO ke baris BOM. Setelah ini biaya,
     * vendor, dan nomor PO di BOM tidak ikut berubah bila PO-nya berubah
     * atau dibatalkan.
     *
     * @return array<string, mixed>
     */
    public function snapshotFrom(PurchaseOrderItem $item): array
    {
        $item->loadMissing(['purchaseOrder.vendor', 'material']);

        return [
            'material_id' => $item->material_id,
            'purchase_order_item_id' => $item->id,
            'line_type' => $item->line_type,
            'item_name' => $item->line_type === 'material' ? $item->material?->name : $item->description,
            'po_number' => $item->purchaseOrder->po_number,
            'vendor_id' => $item->purchaseOrder->vendor_id,
            'vendor_name' => $item->purchaseOrder->vendor?->name,
            'unit_cost' => $item->unit_price,
        ];
    }

    /**
     * Aturan seller: baris PO harus milik PO seller varian ini dan
     * berstatus ordered/received/paid. Memakai scope tunggal
     * PurchaseOrderItem::eligibleForSeller() — dicek ULANG di server meski
     * selector UI sudah menyaring, karena UI bukan batas keamanan.
     */
    public function assertEligible(ProductVariant $variant, PurchaseOrderItem $item, string $field = 'purchase_order_item_id'): void
    {
        $variant->loadMissing('product');

        $eligible = PurchaseOrderItem::eligibleForSeller((int) $variant->product->artist_id)
            ->whereKey($item->id)
            ->exists();

        if (! $eligible) {
            throw ValidationException::withMessages([
                $field => __('bom.line_not_eligible'),
            ]);
        }
    }

    /**
     * Tambah beberapa baris PO sekaligus (atau tidak sama sekali): satu
     * transaksi, jadi permintaan yang salah satu barisnya ditolak tidak
     * meninggalkan baris setengah jadi maupun log yatim. Biaya, vendor, dan
     * nomor PO disalin dari baris PO di server (snapshot).
     *
     * @param  array<int, array{purchase_order_item_id: int, qty?: float|int|string|null}>  $items
     */
    public function addItems(ProductVariant $variant, array $items, User $user): void
    {
        DB::transaction(function () use ($variant, $items, $user) {
            $locked = $this->lockVariant($variant);

            $poItems = PurchaseOrderItem::query()
                ->with(['purchaseOrder.vendor', 'material'])
                ->whereIn('id', collect($items)->pluck('purchase_order_item_id'))
                ->get()
                ->keyBy('id');

            foreach ($items as $index => $input) {
                $this->assertEligible($locked, $poItems[$input['purchase_order_item_id']], "items.{$index}.purchase_order_item_id");
            }

            $existing = $locked->bomLines()
                ->whereIn('purchase_order_item_id', $poItems->keys())
                ->pluck('purchase_order_item_id');

            if ($existing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => __('bom.duplicate_line'),
                ])->status(409);
            }

            foreach ($items as $input) {
                $line = $locked->bomLines()->create($this->snapshotFrom($poItems[$input['purchase_order_item_id']]) + [
                    'qty_needed' => $input['qty'] ?? 1,
                ]);

                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'bom_item_added',
                    entityType: 'ProductVariantBomLine',
                    entityId: $line->id,
                    description: "Menambah baris BOM varian {$locked->sku}: {$line->item_name} ({$line->po_number}) x {$line->qty_needed}.",
                    newValues: $line->only($line->getFillable()),
                );
            }

            $this->syncCostPriceIfComplete($locked, $user->id);
        });
    }

    /**
     * Jalur LEGACY (bahan + jumlah, tanpa baris PO) — dipakai endpoint lama
     * POST /variants/{variant}/bom. Tetap lewat service ini supaya baris
     * legacy pun tercatat di activity log dan tidak bisa dibuat pada BOM
     * yang sudah SELESAI (akan membatalkan syarat selesai).
     */
    public function addLegacyLine(ProductVariant $variant, int $materialId, float|int|string $qty, ?string $notes, User $user): ProductVariantBomLine
    {
        return DB::transaction(function () use ($variant, $materialId, $qty, $notes, $user) {
            $locked = $this->lockVariant($variant);

            if ($locked->bom_complete) {
                throw new BomRuleException(__('bom.bom_complete_blocks_legacy_write'), 'bom_complete');
            }

            $line = $locked->bomLines()->create([
                'material_id' => $materialId,
                'line_type' => 'material',
                'qty_needed' => $qty,
                'notes' => $notes,
            ]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'bom_item_added',
                entityType: 'ProductVariantBomLine',
                entityId: $line->id,
                description: "Menambah baris BOM lama varian {$locked->sku}: bahan #{$line->material_id} x {$line->qty_needed}.",
                newValues: $line->only($line->getFillable()),
            );

            return $line;
        });
    }

    /**
     * 036 — simpan SEKALIGUS jumlah per unit yang berubah (tombol Simpan pada
     * dialog BOM). Satu transaksi, satu kunci varian: semua id dibuktikan milik
     * varian ini SEBELUM ada yang ditulis (409 `bom_line_not_found` bila tidak —
     * mis. baris dihapus orang lain), nilai yang tidak berubah dilewati tanpa
     * jejak audit, tiap baris yang berubah dicatat satu kali (bentuk sama dengan
     * updateLine), dan harga modal disinkronkan SEKALI di akhir bila BOM selesai.
     * Nilai tidak punya versi optimistis: penyimpan terakhir menang (aplikasi
     * satu toko; dicatat di research.md D4).
     *
     * @param  array<int, array{id: int|string, qty_needed: int|string|float}>  $lines
     */
    public function updateQuantities(ProductVariant $variant, array $lines, User $user): ProductVariant
    {
        return DB::transaction(function () use ($variant, $lines, $user) {
            $locked = $this->lockVariant($variant);
            $existing = $locked->bomLines()->get()->keyBy('id');

            foreach ($lines as $input) {
                if (! $existing->has((int) $input['id'])) {
                    throw new BomRuleException(__('bom.line_not_found'), 'bom_line_not_found');
                }
            }

            foreach ($lines as $input) {
                $line = $existing->get((int) $input['id']);
                $old = $line->only(['qty_needed', 'notes']);

                if ($this->num($old['qty_needed']) === $this->num($input['qty_needed'])) {
                    continue;
                }

                $line->update(['qty_needed' => $input['qty_needed']]);

                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'bom_qty_changed',
                    entityType: 'ProductVariantBomLine',
                    entityId: $line->id,
                    description: "Mengubah baris BOM varian {$locked->sku}: {$line->item_name}.",
                    oldValues: $old,
                    newValues: $line->only(['qty_needed', 'notes']),
                );
            }

            $this->syncCostPriceIfComplete($locked, $user->id);

            return $locked;
        });
    }

    /** Ubah jumlah per unit dan/atau catatan. Biaya/sumber TIDAK pernah berubah di sini. */
    public function updateLine(ProductVariantBomLine $line, array $changes, User $user): ProductVariant
    {
        return DB::transaction(function () use ($line, $changes, $user) {
            $variant = $this->lockVariant($line->variant);
            $line->refresh();
            $old = $line->only(['qty_needed', 'notes']);

            $line->update(array_intersect_key($changes, array_flip(['qty_needed', 'notes'])));

            $qtyChanged = $this->num($old['qty_needed']) !== $this->num($line->qty_needed);
            $notesChanged = ($old['notes'] ?? null) !== ($line->notes ?? null);

            if ($qtyChanged || $notesChanged) {
                $this->activityLogger->log(
                    userId: $user->id,
                    action: $qtyChanged ? 'bom_qty_changed' : 'bom_item_updated',
                    entityType: 'ProductVariantBomLine',
                    entityId: $line->id,
                    description: "Mengubah baris BOM varian {$variant->sku}: {$line->item_name}.",
                    oldValues: $old,
                    newValues: $line->only(['qty_needed', 'notes']),
                );
            }

            $this->syncCostPriceIfComplete($variant, $user->id);

            return $variant;
        });
    }

    /**
     * Hapus satu baris. BOM selesai yang menjadi kosong dibuka kembali
     * otomatis (lihat reopenIfInvalid); hasilnya dikembalikan agar UI bisa
     * memberi tahu pengguna.
     *
     * @return array{variant: ProductVariant, reopened: bool}
     */
    public function removeLine(ProductVariantBomLine $line, User $user): array
    {
        return DB::transaction(function () use ($line, $user) {
            $variant = $this->lockVariant($line->variant);
            $line->refresh();
            $snapshot = $line->only($line->getFillable());

            $line->delete();

            $this->activityLogger->log(
                userId: $user->id,
                action: 'bom_item_removed',
                entityType: 'ProductVariantBomLine',
                entityId: $line->id,
                description: "Menghapus baris BOM varian {$variant->sku}: {$line->item_name}.",
                oldValues: $snapshot,
            );

            $reopened = $this->reopenIfInvalid($variant, $user->id);
            $this->syncCostPriceIfComplete($variant, $user->id);

            return ['variant' => $variant, 'reopened' => $reopened];
        });
    }

    /**
     * Salin BOM varian $source ke satu/lebih varian lain DARI PRODUK YANG
     * SAMA (jadi seller yang sama). Baris digandakan lengkap dengan snapshot
     * biaya/vendor/PO, jumlah, dan catatan; sesudahnya tiap varian berdiri
     * sendiri (tidak ada template bersama). Aturan:
     *  - target yang sudah punya baris butuh $confirmReplace (konfirmasi
     *    eksplisit; semua-atau-tidak sama sekali dalam satu transaksi);
     *  - salinan TIDAK PERNAH menandai target selesai, dan target yang
     *    selesai DIBUKA KEMBALI (isinya berganti; harga modal tetap pada
     *    nilai terakhirnya sampai BOM ditandai selesai lagi).
     *
     * @param  iterable<ProductVariant>  $targets
     * @return array<int, array{variant_id: int, sku: string, status: string, rows: int, reopened: bool}>
     */
    public function copy(ProductVariant $source, iterable $targets, bool $confirmReplace, User $user): array
    {
        return DB::transaction(function () use ($source, $targets, $confirmReplace, $user) {
            $sourceRows = $source->bomLines()->get();

            if ($sourceRows->isEmpty()) {
                throw ValidationException::withMessages(['source_variant_id' => __('bom.copy_source_empty')]);
            }

            $targets = collect($targets)->values();
            foreach ($targets as $target) {
                if ($target->id === $source->id) {
                    throw ValidationException::withMessages(['source_variant_id' => __('bom.copy_same_variant')]);
                }
                if ($target->product_id !== $source->product_id) {
                    throw ValidationException::withMessages(['source_variant_id' => __('bom.copy_different_product')]);
                }
            }

            // Kunci target dalam urutan id tetap (hindari deadlock antar-permintaan).
            $locked = ProductVariant::query()
                ->whereIn('id', $targets->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $needConfirmation = $locked->filter(fn (ProductVariant $t) => $t->bomLines()->exists());
            if ($needConfirmation->isNotEmpty() && ! $confirmReplace) {
                throw new BomRuleException(
                    __('bom.copy_requires_confirmation'),
                    'requires_confirmation',
                    [
                        'requires_confirmation' => true,
                        'variants' => $needConfirmation->map(fn (ProductVariant $t) => [
                            'id' => $t->id, 'sku' => $t->sku, 'variant_name' => $t->variant_name, 'rows' => $t->bomLines()->count(),
                        ])->values()->all(),
                    ],
                );
            }

            $copyable = ['material_id', 'purchase_order_item_id', 'line_type', 'item_name', 'po_number', 'vendor_id', 'vendor_name', 'unit_cost', 'qty_needed', 'notes'];
            $results = [];

            foreach ($locked as $target) {
                $removed = $target->bomLines()->get()->map(fn (ProductVariantBomLine $l) => $l->only(['id', 'item_name', 'po_number', 'qty_needed']))->all();
                $target->bomLines()->delete();

                foreach ($sourceRows as $row) {
                    $target->bomLines()->create($row->only($copyable));
                }

                $reopened = (bool) $target->bom_complete;
                if ($reopened) {
                    $target->update(['bom_complete' => false, 'bom_completed_at' => null, 'bom_completed_by' => null]);
                }

                $this->activityLogger->log(
                    userId: $user->id,
                    action: 'bom_copied',
                    entityType: 'ProductVariant',
                    entityId: $target->id,
                    description: "BOM varian {$source->sku} disalin ke {$target->sku} ({$sourceRows->count()} baris, menggantikan ".count($removed).' baris).',
                    oldValues: ['replaced_rows' => $removed],
                    newValues: ['source_variant_id' => $source->id, 'rows' => $sourceRows->count(), 'reopened' => $reopened],
                );

                if ($reopened) {
                    $this->activityLogger->log(
                        userId: $user->id,
                        action: 'bom_reopened',
                        entityType: 'ProductVariant',
                        entityId: $target->id,
                        description: "BOM varian {$target->sku} dibuka kembali karena isinya diganti salinan.",
                    );
                }

                $results[] = ['variant_id' => $target->id, 'sku' => $target->sku, 'status' => 'copied', 'rows' => $sourceRows->count(), 'reopened' => $reopened];
            }

            return $results;
        });
    }

    /**
     * Varian target untuk "salin ke berikutnya" / "salin ke semua".
     *
     * @return \Illuminate\Support\Collection<int, ProductVariant>
     */
    public function copyTargets(ProductVariant $source, string $mode): \Illuminate\Support\Collection
    {
        $siblings = ProductVariant::query()->where('product_id', $source->product_id)->where('id', '!=', $source->id)->orderBy('id');

        if ($mode === 'next') {
            $next = (clone $siblings)->where('id', '>', $source->id)->first();

            if ($next === null) {
                throw ValidationException::withMessages(['mode' => __('bom.copy_no_next')]);
            }

            return collect([$next]);
        }

        $all = $siblings->get();

        if ($all->isEmpty()) {
            throw ValidationException::withMessages(['mode' => __('bom.copy_no_other_variants')]);
        }

        return $all;
    }

    /**
     * Tandai BOM SELESAI: sejak itu cost_price varian = biaya BOM dan
     * dikunci terhadap edit manual. Syarat: ada baris, tidak ada baris
     * legacy (tanpa sumber PO), dan setiap baris valid (jumlah > 0 dan
     * sumber PO masih milik seller varian ini). PO sumber yang DIBATALKAN
     * sengaja tidak menghalangi: biaya tercatat tetap sah, baris hanya
     * ditandai untuk ditinjau. Idempoten untuk BOM yang sudah selesai.
     */
    public function complete(ProductVariant $variant, User $user): ProductVariant
    {
        return DB::transaction(function () use ($variant, $user) {
            $locked = $this->lockVariant($variant);

            if ($locked->bom_complete) {
                return $locked;
            }

            $lines = $locked->bomLines()->with(['material', 'purchaseOrderItem.purchaseOrder'])->get();

            if ($lines->isEmpty()) {
                throw new BomRuleException(__('bom.complete_empty'), 'empty');
            }

            $legacy = $lines->filter(fn (ProductVariantBomLine $l) => $l->isLegacy());
            if ($legacy->isNotEmpty()) {
                $rows = $legacy->map(fn (ProductVariantBomLine $l) => ['id' => $l->id, 'item_name' => $l->material?->name])->values()->all();

                throw new BomRuleException(
                    __('bom.complete_has_legacy', ['rows' => collect($rows)->pluck('item_name')->implode(', ')]),
                    'has_legacy',
                    ['rows' => $rows],
                );
            }

            $artistId = (int) $locked->product->artist_id;
            $invalid = $lines->filter(fn (ProductVariantBomLine $l) => (float) $l->qty_needed <= 0
                || (int) $l->purchaseOrderItem?->purchaseOrder?->artist_id !== $artistId);
            if ($invalid->isNotEmpty()) {
                throw new BomRuleException(
                    __('bom.complete_invalid_row'),
                    'invalid_row',
                    ['rows' => $invalid->map(fn (ProductVariantBomLine $l) => ['id' => $l->id, 'item_name' => $l->item_name])->values()->all()],
                );
            }

            $locked->update(['bom_complete' => true, 'bom_completed_at' => now(), 'bom_completed_by' => $user->id]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'bom_completed',
                entityType: 'ProductVariant',
                entityId: $locked->id,
                description: "BOM varian {$locked->sku} ditandai selesai ({$lines->count()} baris).",
            );

            $this->syncCostPriceIfComplete($locked, $user->id);

            return $locked;
        });
    }

    /** Buka kembali BOM: kunci harga modal dilepas, nilainya TETAP pada angka terakhir. Idempoten. */
    public function reopen(ProductVariant $variant, User $user): ProductVariant
    {
        return DB::transaction(function () use ($variant, $user) {
            $locked = $this->lockVariant($variant);

            if (! $locked->bom_complete) {
                return $locked;
            }

            $locked->update(['bom_complete' => false, 'bom_completed_at' => null, 'bom_completed_by' => null]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'bom_reopened',
                entityType: 'ProductVariant',
                entityId: $locked->id,
                description: "BOM varian {$locked->sku} dibuka kembali.",
            );

            return $locked;
        });
    }

    /**
     * Bentuk BOM yang dikirim ke UI: baris + ringkasan biaya. Satu
     * implementasi untuk GET dan semua respons mutasi, supaya layar tidak
     * pernah menampilkan angka yang berbeda dari penyimpanan.
     *
     * @return array{data: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function payload(ProductVariant $variant, bool $reopened = false): array
    {
        $variant = ProductVariant::query()->findOrFail($variant->id);
        $variant->load(['bomLines.purchaseOrderItem.purchaseOrder', 'product']);

        $breakdown = $this->calculator->breakdown($variant);
        $costs = collect($breakdown['lines'])->keyBy('bom_line_id');
        $cues = $this->sourceCues($variant);

        $rows = $variant->bomLines->map(function (ProductVariantBomLine $line) use ($costs, $cues) {
            $cost = $costs[$line->id];

            return [
                'id' => $line->id,
                'product_variant_id' => $line->product_variant_id,
                'line_type' => $line->line_type,
                'item_name' => $cost['item_name'],
                'is_legacy' => $cost['is_legacy'],
                'material_id' => $line->material_id,
                'material_unit' => $cost['unit'],
                'purchase_order_item_id' => $line->purchase_order_item_id,
                'po_number' => $line->po_number,
                'vendor_id' => $cost['reference_vendor_id'],
                'vendor_name' => $cost['reference_vendor_name'],
                'unit_cost' => $cost['unit_cost'],
                'qty_needed' => $cost['qty_needed'],
                'item_cost' => $cost['line_cost'],
                'po_qty' => $line->purchaseOrderItem?->qty !== null ? (float) $line->purchaseOrderItem->qty : null,
                'notes' => $line->notes,
                'source_cancelled' => $cues[$line->id]['source_cancelled'] ?? false,
                'newer_price' => $cues[$line->id]['newer_price'] ?? null,
            ];
        })->values()->all();

        return [
            'data' => $rows,
            'summary' => [
                'material_cost' => $breakdown['material_cost'],
                'service_cost' => $breakdown['service_cost'],
                'bom_cost' => $breakdown['bom_cost'],
                'has_legacy' => $breakdown['has_legacy'],
                'bom_complete' => (bool) $variant->bom_complete,
                'cost_price' => number_format((float) $variant->cost_price, 2, '.', ''),
                // 036: stok varian saat dibaca — hanya-baca; tidak ada aksi BOM yang mengubah stok.
                'current_stock' => (int) $variant->current_stock,
                'reopened' => $reopened,
            ],
        ];
    }

    /**
     * Isyarat riwayat per baris BOM bersumber PO — dihitung saat dibaca,
     * TIDAK pernah disimpan dan tidak pernah mengubah baris:
     *  - source_cancelled: PO sumbernya dibatalkan (biaya tercatat tetap sah);
     *  - newer_price: baris PO TERBARU (seller sama, ordered/received/paid,
     *    bahan sama / deskripsi jasa sama tanpa beda huruf besar & spasi
     *    tepi) yang lebih baru dari PO sumber dan harganya BERBEDA.
     * Satu query kandidat untuk seluruh baris BOM — tidak tumbuh per baris.
     *
     * @return array<int, array{source_cancelled: bool, newer_price: array<string, mixed>|null}>
     */
    private function sourceCues(ProductVariant $variant): array
    {
        $lines = $variant->bomLines->filter(fn (ProductVariantBomLine $l) => ! $l->isLegacy());

        if ($lines->isEmpty()) {
            return [];
        }

        $keyOf = fn (string $type, ?int $materialId, ?string $name) => $type === 'service'
            ? 's:'.mb_strtolower(trim((string) $name))
            : 'm:'.$materialId;

        $materialIds = $lines->where('line_type', 'material')->pluck('material_id')->filter()->unique()->values();
        $serviceNames = $lines->where('line_type', 'service')->map(fn ($l) => mb_strtolower(trim((string) $l->item_name)))->unique()->values();

        $candidates = PurchaseOrderItem::query()
            ->eligibleForSeller((int) $variant->product->artist_id)
            ->with('purchaseOrder')
            ->where(fn ($q) => $q
                ->where(fn ($m) => $m->where('line_type', 'material')->whereIn('material_id', $materialIds))
                ->orWhere(fn ($s) => $s->where('line_type', 'service')->whereIn(DB::raw('LOWER(TRIM(description))'), $serviceNames)))
            ->get()
            ->groupBy(fn (PurchaseOrderItem $i) => $keyOf($i->line_type, $i->material_id, $i->description));

        // Urutan "lebih baru": tanggal PO (ordered_at, kalau belum ada created_at), lalu id PO.
        $stamp = fn (?\App\Models\PurchaseOrder $po) => $po ? [($po->ordered_at ?? $po->created_at)?->getTimestamp() ?? 0, $po->id] : [0, 0];

        $cues = [];

        foreach ($lines as $line) {
            $sourcePo = $line->purchaseOrderItem?->purchaseOrder;
            $latest = $candidates->get($keyOf($line->line_type, $line->material_id, $line->item_name), collect())
                ->filter(fn (PurchaseOrderItem $c) => $stamp($c->purchaseOrder) > $stamp($sourcePo))
                ->sortByDesc(fn (PurchaseOrderItem $c) => $stamp($c->purchaseOrder))
                ->first();

            $differs = $latest !== null && ReportSplit::cents($latest->unit_price) !== ReportSplit::cents($line->unit_cost);

            $cues[$line->id] = [
                'source_cancelled' => $sourcePo?->status === 'cancelled',
                'newer_price' => $differs ? [
                    'purchase_order_item_id' => $latest->id,
                    'po_number' => $latest->purchaseOrder->po_number,
                    'unit_price' => number_format((float) $latest->unit_price, 2, '.', ''),
                ] : null,
            ];
        }

        return $cues;
    }

    /**
     * Ganti sumber baris BOM ke baris PO lain — TINDAKAN EKSPLISIT pengguna
     * (mis. "pakai harga terbaru"); satu-satunya jalan biaya/vendor/PO sebuah
     * baris berubah. Jumlah per unit tetap; aturan seller + status sama dengan
     * menambah baris; baris PO yang sudah dipakai baris lain BOM ini ditolak.
     * Mengganti ke sumber yang sama = tidak ada perubahan dan tidak ada log.
     */
    public function replaceSource(ProductVariantBomLine $line, PurchaseOrderItem $newItem, User $user): ProductVariant
    {
        return DB::transaction(function () use ($line, $newItem, $user) {
            $variant = $this->lockVariant($line->variant);
            $line->refresh();

            $this->assertEligible($variant, $newItem);

            if ($line->purchase_order_item_id === $newItem->id) {
                return $variant;
            }

            if ($variant->bomLines()->where('purchase_order_item_id', $newItem->id)->where('id', '!=', $line->id)->exists()) {
                throw ValidationException::withMessages(['purchase_order_item_id' => __('bom.duplicate_line')])->status(409);
            }

            $fields = ['purchase_order_item_id', 'material_id', 'line_type', 'item_name', 'po_number', 'vendor_id', 'vendor_name', 'unit_cost'];
            $old = $line->only($fields);

            $line->update($this->snapshotFrom($newItem));

            $this->activityLogger->log(
                userId: $user->id,
                action: 'bom_source_replaced',
                entityType: 'ProductVariantBomLine',
                entityId: $line->id,
                description: "Sumber baris BOM varian {$variant->sku} diganti: {$old['po_number']} -> {$line->po_number} ({$line->item_name}).",
                oldValues: $old + ['unit_cost' => $old['unit_cost'] !== null ? number_format((float) $old['unit_cost'], 2, '.', '') : null],
                newValues: array_merge($line->only($fields), ['unit_cost' => number_format((float) $line->unit_cost, 2, '.', '')]),
            );

            $this->syncCostPriceIfComplete($variant, $user->id);

            return $variant;
        });
    }

    /** Kunci baris varian (FOR UPDATE) supaya sinkron cost_price dan perubahan BOM serentak tidak saling menimpa. */
    private function lockVariant(ProductVariant $variant): ProductVariant
    {
        return ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($variant->id);
    }

    private function num(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    /**
     * Bila BOM varian sudah SELESAI, cost_price = biaya BOM saat ini.
     * No-op untuk varian lain (cost_price manual tetap milik pemilik).
     * Harus dipanggil di dalam transaksi pemanggilnya.
     */
    public function syncCostPriceIfComplete(ProductVariant $variant, ?int $userId): void
    {
        if (! $variant->bom_complete) {
            return;
        }

        $variant->unsetRelation('bomLines');
        $bomCost = $this->calculator->breakdown($variant)['bom_cost'];
        $old = ReportSplit::money(ReportSplit::cents($variant->cost_price));

        if (ReportSplit::cents($bomCost) === ReportSplit::cents($old)) {
            return;
        }

        $variant->update(['cost_price' => $bomCost]);

        $this->activityLogger->log(
            userId: $userId,
            action: 'cost_price_synced',
            entityType: 'ProductVariant',
            entityId: $variant->id,
            description: "Harga modal varian {$variant->sku} mengikuti BOM: {$old} -> {$bomCost}.",
            oldValues: ['cost_price' => $old],
            newValues: ['cost_price' => $bomCost],
        );
    }

    /**
     * BOM selesai yang menjadi kosong tidak boleh tetap "selesai": dibuka
     * kembali otomatis, cost_price tetap pada nilai terakhirnya.
     *
     * @return bool true bila varian baru saja dibuka kembali
     */
    public function reopenIfInvalid(ProductVariant $variant, ?int $userId): bool
    {
        if (! $variant->bom_complete || $variant->bomLines()->exists()) {
            return false;
        }

        $variant->update(['bom_complete' => false, 'bom_completed_at' => null, 'bom_completed_by' => null]);

        $this->activityLogger->log(
            userId: $userId,
            action: 'bom_reopened',
            entityType: 'ProductVariant',
            entityId: $variant->id,
            description: "BOM varian {$variant->sku} dibuka kembali otomatis karena menjadi kosong.",
        );

        return true;
    }
}
