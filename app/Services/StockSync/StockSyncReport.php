<?php

namespace App\Services\StockSync;

use App\Exports\MultiSheetArrayExport;
use App\Exports\SheetArrayExport;
use App\Models\User;
use App\Services\StockMovementReferences;
use App\Support\ModeGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 051-stock-sync-from-transactions — laporan Excel untuk DITINJAU (dry-run) dan sebagai catatan akhir (sesudah terapkan).
 *
 * Tiga lembar: Ringkasan (mode, kode rencana, hitungan per status), Varian (satu baris per varian, semua angka yang
 * membentuk "stok seharusnya") dan Sumber (tiap pergerakan pengurang beserta nomor transaksinya, supaya pemilik bisa
 * mencocokkan angkanya dengan transaksi aslinya).
 *
 * TIDAK memuat data pelanggan: nomor order/pre-order saja. Berkas disimpan di disk privat `local`
 * (storage/app/private/stock-sync/), bukan disk publik. Nama penjual/produk/varian diketik pengguna; SheetArrayExport
 * menyimpan teks berawalan "=" sebagai teks (bukan rumus).
 */
class StockSyncReport
{
    public const DIRECTORY = 'stock-sync';

    private const VARIANT_HEADINGS = [
        'penjual', 'produk', 'varian', 'sku', 'stok_sekarang', 'acuan_id', 'acuan_waktu', 'acuan_sebelumnya_waktu',
        'terjual', 'retur', 'serah_terima_preorder', 'pengurang_bersih', 'stok_seharusnya', 'selisih', 'kekurangan',
        'status', 'catatan',
    ];

    private const KIND_LABELS = [
        'sale' => 'Penjualan POS',
        'return' => 'Retur (void)',
        'preorder_handover' => 'Serah-terima pre-order',
    ];

    public function __construct(private StockMovementReferences $references) {}

    /**
     * @param  'dryrun'|'applied'  $kind
     * @param  array{changed: int, units: int}|null  $result
     */
    public function build(array $plan, string $kind, ?User $by = null, ?array $result = null): MultiSheetArrayExport
    {
        return new MultiSheetArrayExport([
            new SheetArrayExport('Ringkasan', ['item', 'nilai'], $this->summaryRows($plan, $kind, $by, $result)),
            new SheetArrayExport('Varian', self::VARIANT_HEADINGS, $this->variantRows($plan)),
            new SheetArrayExport('Sumber', ['sku', 'jenis', 'nomor_transaksi', 'kuantitas', 'waktu'], $this->sourceRows($plan)),
        ]);
    }

    /** Simpan ke disk privat; kembalikan jalur MUTLAK berkas. */
    public function store(array $plan, string $kind, ?User $by = null, ?array $result = null): string
    {
        $path = self::DIRECTORY.'/stock-sync-'.$kind.'-'.now()->format('Ymd-His').'.xlsx';
        Excel::store($this->build($plan, $kind, $by, $result), $path, 'local');

        return Storage::disk('local')->path($path);
    }

    private function summaryRows(array $plan, string $kind, ?User $by, ?array $result): array
    {
        $rows = [
            ['item' => 'Jenis laporan', 'nilai' => $kind === 'applied' ? 'HASIL penerapan' : 'DRY-RUN (belum ada yang diubah)'],
            ['item' => 'Mode data', 'nilai' => 'LIVE'],
            ['item' => 'Dibuat pada', 'nilai' => $plan['generated_at']],
            ['item' => 'Kode rencana', 'nilai' => $plan['hash']],
        ];

        if ($by !== null) {
            $rows[] = ['item' => 'Dijalankan oleh', 'nilai' => $by->username];
        }
        if ($result !== null) {
            $rows[] = ['item' => 'Varian yang diubah', 'nilai' => $result['changed']];
            $rows[] = ['item' => 'Unit dikurangkan', 'nilai' => $result['units']];
        }

        foreach ($plan['counts'] as $status => $count) {
            $rows[] = ['item' => "Jumlah varian: {$status}", 'nilai' => $count];
        }

        $rows[] = ['item' => 'Total pengurang bersih (varian yang ditulis)', 'nilai' => $plan['totals']['taken']];
        $rows[] = ['item' => 'Total kekurangan (unit terjual melebihi stok, dibatasi ke 0)', 'nilai' => $plan['totals']['shortfall']];

        return $rows;
    }

    private function variantRows(array $plan): array
    {
        return array_map(fn (array $r) => [
            'penjual' => $r['seller'], 'produk' => $r['product'], 'varian' => $r['variant'], 'sku' => $r['sku'],
            'stok_sekarang' => $r['current_stock'], 'acuan_id' => $r['baseline_id'], 'acuan_waktu' => $r['baseline_at'],
            'acuan_sebelumnya_waktu' => $r['previous_baseline_at'],
            'terjual' => $r['sold'], 'retur' => $r['returned'], 'serah_terima_preorder' => $r['handed_over'],
            'pengurang_bersih' => $r['taken'], 'stok_seharusnya' => $r['new_stock'], 'selisih' => $r['delta'],
            'kekurangan' => $r['shortfall'], 'status' => $r['status'], 'catatan' => $r['note'],
        ], $plan['rows']);
    }

    private function sourceRows(array $plan): array
    {
        $skus = array_column($plan['rows'], 'sku', 'variant_id');
        $movements = collect($plan['sources'])->flatten(1);

        // Satu pemanggilan berkelompok (bukan per baris); scope LIVE dipertahankan lewat runAs.
        $numbers = ModeGate::runAs(ModeGate::LIVE, fn () => $this->references->resolve($movements));

        return $movements->map(fn ($m) => [
            'sku' => $skus[$m->variant_id] ?? '',
            'jenis' => self::KIND_LABELS[$m->type] ?? $m->type,
            'nomor_transaksi' => $numbers[$m->id]['number'] ?? '',
            'kuantitas' => (int) $m->qty_change,
            'waktu' => $m->created_at === null ? null : Carbon::parse($m->created_at)->toDateTimeString(),
        ])->values()->all();
    }
}
