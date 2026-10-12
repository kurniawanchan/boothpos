<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 045 (T008) — rekonsiliasi SEBELUM/SESUDAH tiap tahap migrasi outlet (SC-002, SC-011).
 *
 *   php artisan outlets:reconcile --before     # simpan cuplikan keadaan sekarang
 *   php artisan outlets:reconcile --after      # simpan cuplikan setelah migrasi
 *   php artisan outlets:reconcile --compare    # bandingkan; kode keluar ≠ 0 bila ADA selisih
 *
 * Cuplikan tidak bergantung pada nama (artists/outlets, artist_id/home_outlet_id): tabel dan kolom dipetakan lewat
 * daftar kandidat, jadi pembanding yang sama bekerja untuk Stage 1 (rename) dan tahap sesudahnya. Yang dicuplik:
 * jumlah baris, identitas outlet (id, kode, nama), uang per pemilik item, total settlement, saldo stok per varian
 * dan total bahan. Pemeriksaan khusus tahap (kolom outlet_id terisi, invarian I1/I2 stok) ditambahkan oleh tahap
 * terkait lewat `stageChecks()` — satu tempat, tanpa perintah baru per tahap.
 *
 * Perintah ini TIDAK mengubah data bisnis; ia hanya membaca dan menulis berkas JSON di `--dir`
 * (bawaan storage/app/reconcile).
 */
class OutletsReconcile extends Command
{
    protected $signature = 'outlets:reconcile {--before} {--after} {--compare} {--dir= : Folder berkas cuplikan}';

    protected $description = 'Cuplik dan bandingkan jumlah/total sebelum dan sesudah migrasi outlet (kode keluar ≠ 0 bila ada selisih)';

    /** Nama logis → kandidat tabel (yang pertama ada yang dipakai). */
    private const TABLES = [
        // Sebelum migrasi 045 tabelnya masih `artists`; sesudahnya `outlets`.
        // Kandidat lama wajib ada, kalau tidak cuplikan 'before' jadi 0 dan
        // perbandingan kehilangan artinya (ditemukan saat cuplikan dev pertama).
        'outlets' => ['outlets', 'artists'],
        'products' => ['products'],
        'product_variants' => ['product_variants'],
        'orders' => ['orders'],
        'order_items' => ['order_items'],
        'preorders' => ['preorders'],
        'preorder_items' => ['preorder_items'],
        'purchase_orders' => ['purchase_orders'],
        'settlements' => ['outlet_settlements', 'artist_settlements'],
        'stock_movements' => ['stock_movements'],
        'materials' => ['materials'],
        'opening_cash_entries' => ['session_opening_cash_entries'],
        'cashier_sessions' => ['cashier_sessions'],
        'dining_tables' => ['dining_tables'],
        'users' => ['users'],
        'roles' => ['roles'],
    ];

    public function handle(): int
    {
        $modes = array_filter([$this->option('before') ? 'before' : null, $this->option('after') ? 'after' : null, $this->option('compare') ? 'compare' : null]);
        if (count($modes) !== 1) {
            $this->error('Pilih tepat satu: --before, --after, atau --compare.');

            return self::INVALID;
        }

        $dir = $this->option('dir') ?: storage_path('app/reconcile');
        File::ensureDirectoryExists($dir);
        $mode = reset($modes);

        if ($mode !== 'compare') {
            $snapshot = $this->snapshot();
            File::put("{$dir}/{$mode}.json", json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Cuplikan '{$mode}' disimpan: {$dir}/{$mode}.json");
            $this->line('  baris: '.collect($snapshot['counts'])->map(fn ($n, $k) => "{$k}={$n}")->implode(' '));

            return self::SUCCESS;
        }

        foreach (['before', 'after'] as $name) {
            if (! File::exists("{$dir}/{$name}.json")) {
                $this->error("Berkas {$dir}/{$name}.json belum ada — jalankan --{$name} dulu.");

                return self::FAILURE;
            }
        }

        $before = json_decode(File::get("{$dir}/before.json"), true);
        $after = json_decode(File::get("{$dir}/after.json"), true);
        $differences = array_merge($this->diff($before, $after), $this->stageChecks());

        if ($differences === []) {
            $this->info('REKONSILIASI OK — tidak ada selisih.');

            return self::SUCCESS;
        }

        $this->error('REKONSILIASI GAGAL — '.count($differences).' selisih:');
        foreach ($differences as $line) {
            $this->line('  - '.$line);
        }

        return self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $counts = [];
        foreach (self::TABLES as $logical => $candidates) {
            $table = $this->resolveTable($candidates);
            $counts[$logical] = $table ? DB::table($table)->count() : 0;
        }

        $outletTable = $this->resolveTable(self::TABLES['outlets']);
        $itemOwnerColumn = $this->resolveColumn('order_items', ['home_outlet_id', 'artist_id']);
        $productOwnerColumn = $this->resolveColumn('products', ['home_outlet_id', 'artist_id']);
        $settlementTable = $this->resolveTable(self::TABLES['settlements']);
        $settlementOutletColumn = $settlementTable ? $this->resolveColumn($settlementTable, ['outlet_id', 'artist_id']) : null;

        return [
            'taken_at' => now()->toIso8601String(),
            'counts' => $counts,
            'outlets' => $outletTable ? DB::table($outletTable)->orderBy('id')->get(['id', 'code', 'name', 'is_active'])->map(fn ($r) => (array) $r)->all() : [],
            'stock' => [
                'variants_total' => (int) DB::table('product_variants')->sum('current_stock'),
                'materials_total' => (string) DB::table('materials')->sum('current_stock'),
                'per_variant' => DB::table('product_variants')->orderBy('id')->pluck('current_stock', 'id')->all(),
            ],
            'money' => [
                'orders_total' => $this->money(DB::table('orders')->where('status', 'completed')->sum('total_amount')),
                'order_items_line_total' => $this->money(DB::table('order_items')->sum('line_total')),
                'order_items_by_owner' => $itemOwnerColumn
                    ? DB::table('order_items')->selectRaw("{$itemOwnerColumn} as owner, SUM(line_total) as total, SUM(qty) as units")->groupBy($itemOwnerColumn)->orderBy($itemOwnerColumn)->get()
                        ->mapWithKeys(fn ($r) => [(string) $r->owner => ['total' => $this->money($r->total), 'units' => (int) $r->units]])->all()
                    : [],
                'products_by_owner' => $productOwnerColumn
                    ? DB::table('products')->selectRaw("{$productOwnerColumn} as owner, COUNT(*) as n")->groupBy($productOwnerColumn)->orderBy($productOwnerColumn)->pluck('n', 'owner')->all()
                    : [],
                'settlements' => $settlementTable && $settlementOutletColumn
                    ? DB::table($settlementTable)->orderBy($settlementOutletColumn)->get()->map(fn ($r) => [
                        'outlet' => (int) $r->{$settlementOutletColumn}, 'event' => (int) $r->event_id,
                        'total_sales' => $this->money($r->total_sales), 'total_units' => (int) $r->total_units, 'payable' => $this->money($r->payable_amount),
                    ])->all()
                    : [],
                'preorders_total' => $this->money(DB::table('preorders')->where('status', '!=', 'cancelled')->sum('total_amount')),
                'preorders_paid' => $this->money(DB::table('preorders')->where('status', '!=', 'cancelled')->sum('paid_amount')),
                'purchase_orders_total' => $this->money(DB::table('purchase_orders')->sum('total_amount')),
            ],
        ];
    }

    /** @return list<string> */
    private function diff(array $before, array $after): array
    {
        $out = [];

        foreach ($before['counts'] as $name => $n) {
            if (($after['counts'][$name] ?? null) !== $n) {
                $out[] = "jumlah baris {$name}: {$n} → ".($after['counts'][$name] ?? 'hilang');
            }
        }
        if ($before['outlets'] !== $after['outlets']) {
            $out[] = 'identitas outlet (id/kode/nama/aktif) berubah';
        }
        foreach (['variants_total', 'materials_total'] as $k) {
            if ($before['stock'][$k] !== $after['stock'][$k]) {
                $out[] = "stok {$k}: {$before['stock'][$k]} → {$after['stock'][$k]}";
            }
        }
        foreach ($before['stock']['per_variant'] as $id => $qty) {
            if (($after['stock']['per_variant'][$id] ?? null) !== $qty) {
                $out[] = "stok varian #{$id}: {$qty} → ".($after['stock']['per_variant'][$id] ?? 'hilang');
            }
        }
        foreach ($before['money'] as $k => $v) {
            if ($before['money'][$k] !== ($after['money'][$k] ?? null)) {
                $out[] = "uang/kelompok '{$k}' berubah";
            }
        }

        return $out;
    }

    /**
     * Pemeriksaan khusus tahap, dijalankan setiap --compare. Tahap lanjut menambah isinya:
     *  - Stage 2: tidak ada outlet_id NULL pada tabel operasional;
     *  - Stage 5: invarian I1 (saldo = Σ ledger) dan I2 (total cache = Σ saldo outlet).
     *
     * @return list<string>
     */
    private function stageChecks(): array
    {
        $failures = [];

        // Stage 2: setiap tabel operasional punya outlet, dan outlet anak = outlet induknya (D7).
        foreach (['cashier_sessions', 'orders', 'payments', 'kitchen_tickets', 'dining_tables', 'stations', 'preorders'] as $table) {
            if (! Schema::hasColumn($table, 'outlet_id')) {
                continue;
            }
            $missing = DB::table($table)->whereNull('outlet_id')->count();
            if ($missing > 0) {
                $failures[] = "S2: {$table} punya {$missing} baris tanpa outlet_id";
            }
        }

        if (Schema::hasColumn('orders', 'outlet_id') && Schema::hasColumn('cashier_sessions', 'outlet_id')) {
            $drift = DB::table('orders')->join('cashier_sessions', 'cashier_sessions.id', '=', 'orders.session_id')
                ->whereColumn('orders.outlet_id', '!=', 'cashier_sessions.outlet_id')->count();
            if ($drift > 0) {
                $failures[] = "S2: {$drift} order punya outlet berbeda dari shift-nya";
            }
        }

        if (Schema::hasColumn('payments', 'outlet_id') && Schema::hasColumn('orders', 'outlet_id')) {
            $drift = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')
                ->whereColumn('payments.outlet_id', '!=', 'orders.outlet_id')->count();
            if ($drift > 0) {
                $failures[] = "S2: {$drift} pembayaran punya outlet berbeda dari ordernya";
            }
        }

        // Stage 5: I2 (total tersimpan = Σ saldo outlet) dan I1 (saldo = stock_after pergerakan TERAKHIR pasangan itu —
        // kontinuitas buku besar; tahan terhadap saldo lama yang tak pernah punya riwayat awal).
        if (Schema::hasTable('variant_outlet_stock')) {
            foreach ([
                ['variant', 'product_variants', 'variant_outlet_stock', 'variant_id', 'stock_movements'],
                ['bahan', 'materials', 'material_outlet_stock', 'material_id', 'material_stock_movements'],
            ] as [$label, $items, $balances, $fk, $ledger]) {
                $i2 = (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$items} i
                    LEFT JOIN (SELECT {$fk} AS id, SUM(current_stock) AS s FROM {$balances} GROUP BY {$fk}) b ON b.id = i.id
                    WHERE i.current_stock <> COALESCE(b.s, 0)")->n;
                if ($i2 > 0) {
                    $failures[] = "S5/I2: {$i2} {$label} dengan total tersimpan ≠ Σ saldo outlet";
                }

                $i1 = (int) DB::selectOne("SELECT COUNT(*) AS n FROM (
                        SELECT {$fk}, outlet_id, MAX(id) AS mid FROM {$ledger} GROUP BY {$fk}, outlet_id
                    ) last
                    JOIN {$ledger} m ON m.id = last.mid
                    LEFT JOIN {$balances} b ON b.{$fk} = last.{$fk} AND b.outlet_id = last.outlet_id
                    WHERE COALESCE(b.current_stock, -1) <> m.stock_after")->n;
                if ($i1 > 0) {
                    $failures[] = "S5/I1: {$i1} pasangan {$label}/outlet dengan saldo ≠ stock_after pergerakan terakhir";
                }
            }
        }

        // Stage 3: setiap pasangan (outlet × produk) di mode data yang sama punya baris `outlet_item_settings`
        // (tanpa baris = dianggap dibawa, tapi baris yang hilang berarti materialisasi tertinggal), dan tidak ada
        // harga outlet yatim (varian/outlet terhapus).
        if (Schema::hasTable('outlet_item_settings')) {
            $missing = (int) DB::selectOne('SELECT COUNT(*) AS n FROM outlets o JOIN products p ON p.data_mode = o.data_mode
                LEFT JOIN outlet_item_settings s ON s.outlet_id = o.id AND s.product_id = p.id
                WHERE o.deleted_at IS NULL AND p.deleted_at IS NULL AND s.id IS NULL')->n;
            if ($missing > 0) {
                $failures[] = "S3: {$missing} pasangan outlet × produk tanpa baris outlet_item_settings";
            }
        }
        if (Schema::hasTable('variant_outlet_prices')) {
            $orphans = (int) DB::selectOne('SELECT COUNT(*) AS n FROM variant_outlet_prices v
                LEFT JOIN outlets o ON o.id = v.outlet_id LEFT JOIN product_variants pv ON pv.id = v.variant_id
                WHERE o.id IS NULL OR pv.id IS NULL')->n;
            if ($orphans > 0) {
                $failures[] = "S3: {$orphans} harga outlet menunjuk outlet/varian yang tidak ada";
            }
        }

        return $failures;
    }

    private function resolveTable(array $candidates): ?string
    {
        foreach ($candidates as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function resolveColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
