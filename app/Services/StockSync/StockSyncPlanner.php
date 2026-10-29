<?php

namespace App\Services\StockSync;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\MasterDataImportService;
use App\Support\ModeGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 051-stock-sync-from-transactions — PERENCANA (hanya membaca): menghitung, per varian LIVE, berapa unit yang sudah
 * keluar lewat penjualan / serah-terima pre-order SEBELUM pembaruan stok manual terakhir, sehingga stok yang diketik
 * pemilik (yang mengabaikan transaksi itu) bisa dikoreksi. Kelas ini TIDAK menulis apa pun; penulisnya
 * StockSyncApplier, supaya dry-run secara struktur tidak mungkin mengubah data.
 *
 * Aturan non-obvious (alasannya di specs/051-stock-sync-from-transactions/research.md):
 *
 * - SUMBER = buku besar `stock_movements`, bukan tabel transaksi: `sale` (−) dan `preorder_handover` (−) dikurangi
 *   `return` (+, hasil void). Pre-order yang belum diserahkan/dibatalkan memang tidak punya pergerakan pengurang,
 *   jadi tidak ikut (keputusan pemilik Q1 = A) tanpa aturan status tambahan.
 * - TITIK ACUAN = `adjustment` terakhir yang BUKAN koreksi kelas ini (`reference_type = stock_sync`). Urutan memakai
 *   `id`, BUKAN `created_at`: waktu hanya presisi detik, sedangkan id auto-increment dan buku besar append-only.
 * - JENDELA = pergerakan dengan id di antara acuan sebelumnya (pembaruan manual sebelumnya, atau awal) dan acuan ini.
 * - OPERAN = stok SEKARANG, bukan angka yang diketik: transaksi sesudah pembaruan sudah mengubah current_stock dan
 *   tidak boleh terhapus oleh koreksi.
 * - Hasil < 0 dibatasi ke 0 (keputusan pemilik Q2 = B); kekurangannya DILAPORKAN, tidak dibuang.
 * - Idempoten: acuan yang sudah punya pergerakan `stock_sync` berstatus `sudah_disinkronkan`.
 * - Semua kueri lewat Eloquent di dalam ModeGate::runAs('live'): DB::table akan melewati DataModeScope (CLAUDE.md).
 */
class StockSyncPlanner
{
    public const SYNC_REFERENCE = 'stock_sync';

    public const STATUS_CHANGE = 'akan_diubah';

    public const STATUS_ATTENTION = 'perlu_perhatian';

    public const STATUS_NONE = 'tidak_ada_perubahan';

    public const STATUS_SYNCED = 'sudah_disinkronkan';

    public const STATUS_SKIPPED = 'dilewati';

    /** Status yang menjadi bagian dari rencana tulis (dan dari kode rencana/hash). */
    public const WRITING_STATUSES = [self::STATUS_CHANGE, self::STATUS_ATTENTION];

    /**
     * @return array{rows: list<array<string, mixed>>, hash: string, counts: array<string, int>, totals: array{taken: int, shortfall: int}, sources: array<int, Collection<int, StockMovement>>, generated_at: string}
     */
    public function plan(): array
    {
        return ModeGate::runAs(ModeGate::LIVE, fn () => $this->build());
    }

    private function build(): array
    {
        // 1) Seluruh pembaruan manual (urut id) dan penanda koreksi yang sudah ada — dua kueri, bukan per varian.
        $manual = StockMovement::query()
            ->where('type', 'adjustment')
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', '<>', self::SYNC_REFERENCE))
            ->orderBy('id')
            ->get(['id', 'variant_id', 'reason', 'created_at'])
            ->groupBy('variant_id');

        $synced = StockMovement::query()
            ->where('type', 'adjustment')
            ->where('reference_type', self::SYNC_REFERENCE)
            ->pluck('reference_id')
            ->flip();

        // 2) Varian: yang belum dihapus, ditambah yang sudah dihapus tetapi punya riwayat pembaruan manual.
        $variants = ProductVariant::withTrashed()
            ->with(['product' => fn ($q) => $q->withTrashed(), 'product.artist' => fn ($q) => $q->withTrashed()])
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhereIn('id', $manual->keys()))
            ->orderBy('id')
            ->get();

        // 3) Pergerakan pengurang untuk varian yang punya acuan — satu kueri, dikelompokkan di PHP.
        $movements = StockMovement::query()
            ->whereIn('type', ['sale', 'return', 'preorder_handover'])
            ->whereIn('variant_id', $manual->keys())
            ->orderBy('id')
            ->get()
            ->groupBy('variant_id');

        $rows = [];
        $sources = [];

        foreach ($variants as $variant) {
            [$row, $window] = $this->row($variant, $manual->get($variant->id, collect()), $movements->get($variant->id, collect()), $synced);
            $rows[] = $row;

            if ($window->isNotEmpty() && in_array($row['status'], [...self::WRITING_STATUSES, self::STATUS_SYNCED], true)) {
                $sources[$variant->id] = $window;
            }
        }

        $counts = array_fill_keys([self::STATUS_CHANGE, self::STATUS_ATTENTION, self::STATUS_NONE, self::STATUS_SYNCED, self::STATUS_SKIPPED], 0);
        $taken = 0;
        $shortfall = 0;

        foreach ($rows as $row) {
            $counts[$row['status']]++;
            if (in_array($row['status'], self::WRITING_STATUSES, true)) {
                $taken += $row['taken'];
                $shortfall += $row['shortfall'];
            }
        }

        return [
            'rows' => $rows,
            'hash' => $this->hash($rows),
            'counts' => $counts,
            'totals' => ['taken' => $taken, 'shortfall' => $shortfall],
            'sources' => $sources,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  Collection<int, StockMovement>  $manual  pembaruan manual varian ini, urut id
     * @param  Collection<int, StockMovement>  $movements  pergerakan pengurang varian ini, urut id
     * @return array{0: array<string, mixed>, 1: Collection<int, StockMovement>}
     */
    private function row(ProductVariant $variant, Collection $manual, Collection $movements, Collection $synced): array
    {
        $baseline = $manual->last();
        $previous = $manual->count() > 1 ? $manual->get($manual->count() - 2) : null;

        $row = [
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'seller' => $variant->product?->artist?->name,
            'product' => $variant->product?->name,
            'variant' => $variant->variant_name,
            'current_stock' => (int) $variant->current_stock,
            'baseline_id' => $baseline?->id,
            'baseline_at' => $this->time($baseline?->created_at),
            'previous_baseline_id' => $previous?->id,
            'previous_baseline_at' => $this->time($previous?->created_at),
            'sold' => 0,
            'returned' => 0,
            'handed_over' => 0,
            'taken' => 0,
            'new_stock' => (int) $variant->current_stock,
            'delta' => 0,
            'shortfall' => 0,
            'status' => self::STATUS_NONE,
            'note' => '',
        ];

        if ($variant->trashed()) {
            return [array_merge($row, ['status' => self::STATUS_SKIPPED, 'note' => 'varian sudah dihapus']), collect()];
        }

        if ($baseline === null) {
            return [array_merge($row, ['status' => self::STATUS_SKIPPED, 'note' => 'belum ada pembaruan stok manual']), collect()];
        }

        $window = $movements->filter(fn (StockMovement $m) => $m->id < $baseline->id && $m->id > ($previous?->id ?? 0))->values();

        $row['sold'] = (int) -$window->where('type', 'sale')->sum('qty_change');
        $row['returned'] = (int) $window->where('type', 'return')->sum('qty_change');
        $row['handed_over'] = (int) -$window->where('type', 'preorder_handover')->sum('qty_change');
        $row['taken'] = $row['sold'] + $row['handed_over'] - $row['returned'];

        // Pembaruan sebelumnya yang BUKAN stok awal varian baru berarti pemilik mengetik stok lebih dari sekali;
        // hanya jendela terakhir yang dikoreksi — diberi catatan agar ditinjau di Excel.
        if ($previous !== null && $previous->reason !== MasterDataImportService::REASON_INITIAL) {
            $row['note'] = 'beberapa pembaruan stok manual; hanya transaksi di antara dua pembaruan terakhir yang dihitung';
        }

        if ($synced->has($baseline->id)) {
            return [array_merge($row, ['status' => self::STATUS_SYNCED]), $window];
        }

        if ($row['taken'] <= 0) {
            return [$row, $window];
        }

        $row['new_stock'] = max(0, $row['current_stock'] - $row['taken']);
        $row['delta'] = $row['new_stock'] - $row['current_stock'];
        $row['shortfall'] = max(0, $row['taken'] - $row['current_stock']);
        $row['status'] = $row['shortfall'] > 0 ? self::STATUS_ATTENTION : self::STATUS_CHANGE;

        return [$row, $window];
    }

    /** StockMovement tidak meng-cast created_at (kolom datetime biasa, bisa string): normalkan di satu tempat. */
    private function time(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateTimeString();
    }

    /**
     * Kode rencana: terikat pada ANGKA yang ditinjau (acuan, stok sekarang, pengurang, selisih). Transaksi atau
     * perubahan stok baru mengubah angka itu, sehingga persetujuan lama otomatis tidak berlaku.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function hash(array $rows): string
    {
        $material = [];

        foreach ($rows as $row) {
            if (in_array($row['status'], self::WRITING_STATUSES, true)) {
                $material[] = [$row['variant_id'], $row['baseline_id'], $row['current_stock'], $row['taken'], $row['delta']];
            }
        }

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }
}
