<?php

namespace App\Services\DemoClone;

use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mengganti seluruh data DEMO dengan salinan persis data LIVE.
 *
 * Aturan yang tidak boleh dilanggar: baris LIVE hanya DIBACA. Setiap SELECT
 * sumber memakai scope 'live', setiap tulisan memberi data_mode 'demo',
 * setiap DELETE/UPDATE difilter 'demo'. Query memakai DB::table() +
 * CloneRegistry::scope(), BUKAN model — DataModeScope mengikuti mode AKTIF
 * dan akan terbalik bila system_mode = demo.
 */
class LiveToDemoCloner
{
    /** @var array<string,array<int,int>> tabel => [id live => id demo] */
    private array $maps = [];

    /** @var array<int,array{0:string,1:string}> id produk live => [prefix live, prefix demo] */
    private array $prefixes = [];

    /** @var array<string,int> baris yang dilewati per tabel (mis. bukti bayar tanpa berkas) */
    private array $skipped = [];

    private int $referencesDropped = 0;

    /**
     * Angka untuk simulasi (dry run). Tidak menulis apa pun.
     */
    public function plan(): array
    {
        $tables = [];
        $paths = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            $tables[$table] = [
                'demo_delete' => DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))->count(),
                'live_clone' => DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->count(),
            ];

            foreach ($spec['files'] as $column => $disk) {
                $found = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))
                    ->whereNotNull($column)->where($column, '!=', '')->pluck($column);

                foreach ($found as $path) {
                    $paths[$disk][(string) $path] = true;
                }
            }
        }

        $files = ['files' => 0, 'bytes' => 0, 'missing' => 0];

        foreach ($paths as $disk => $set) {
            foreach (FileCopier::measure($disk, array_map('strval', array_keys($set))) as $key => $value) {
                $files[$key] += $value;
            }
        }

        return ['tables' => $tables, 'files' => $files];
    }

    /**
     * Seluruhnya dalam SATU transaksi: sidik jari LIVE → cek tak ada LIVE yang
     * menunjuk DEMO → hapus DEMO → salin → verifikasi → sidik jari LIVE lagi
     * (harus sama) → catat log. Salinan berkas dihapus bila gagal; berkas DEMO
     * lama yang tak dipakai siapa pun dihapus HANYA sesudah commit (rollback
     * tak boleh menghilangkan berkas).
     *
     * PRASYARAT: dipanggil DI LUAR transaksi DB lain. Penghapusan berkas lama
     * sesudah DB::transaction() mengandaikan transaksi itu adalah commit yang
     * sebenarnya; di dalam transaksi luar, "commit" itu baru savepoint dan
     * berkas bisa terhapus untuk data yang kemudian di-rollback. Perintah
     * artisan memanggilnya di tingkat atas. Tidak dipaksa di kode karena test
     * dibungkus transaksi RefreshDatabase.
     *
     * Hanya melempar SEBELUM commit. Kegagalan membersihkan berkas lama
     * (sesudah commit) tidak dilempar: DEMO sudah terlanjur diganti, jadi
     * pemanggil tidak boleh mengira semuanya dibatalkan — dilaporkan lewat
     * 'files_cleanup_failed'.
     */
    public function run(): array
    {
        $copier = new FileCopier;
        $stale = [];

        try {
            $report = DB::transaction(function () use ($copier, &$stale) {
                $before = LiveFingerprint::capture();

                $this->assertNoLiveReferencesToDemo();

                $stale = $this->demoFiles();
                $deleted = $this->wipeDemo();
                $codes = new CodeAllocator;
                $cloned = $this->cloneAll($copier, $codes);

                $this->afterClone();

                $this->verifyIntegrity();
                $this->verifyFiles();

                $changed = LiveFingerprint::diff($before, LiveFingerprint::capture());

                if ($changed !== []) {
                    throw new CloneException('Data LIVE berubah selama proses (tabel: '.implode(', ', $changed).'); dibatalkan.');
                }

                // Hanya hitungan: tidak ada nilai baris (PII) di log.
                app(ActivityLogger::class)->log(
                    null,
                    'cloned_live_to_demo',
                    'system',
                    null,
                    'Data DEMO diganti dengan salinan data LIVE.',
                    null,
                    ['deleted' => $deleted, 'cloned' => $cloned],
                );

                return [
                    'deleted' => $deleted,
                    'cloned' => $cloned,
                    'skipped' => $this->skipped,
                    'files' => ['copied' => $copier->copied, 'bytes' => $copier->bytes, 'missing' => $copier->missing],
                    'references_dropped' => $this->referencesDropped,
                    'codes' => $codes->mapping(),
                ];
            });
        } catch (\Throwable $e) {
            // Galat ASLI yang harus sampai ke pemanggil; kegagalan membersihkan
            // salinan tidak boleh menggantikannya (hanya nama kelas yang dicatat).
            try {
                $copier->discardCopies();
            } catch (\Throwable $discardError) {
                Log::warning('app:clone-live-to-demo: penghapusan salinan berkas gagal: '.get_class($discardError));
            }

            throw $e;
        }

        $report['files_cleanup_failed'] = false;

        try {
            $this->deleteUnreferencedFiles($stale);
        } catch (\Throwable $e) {
            // Sudah commit: jangan membuat pemanggil mengira semuanya dibatalkan.
            // Hanya nama kelas yang dicatat (pesan bisa memuat path).
            Log::warning('app:clone-live-to-demo: pembersihan berkas DEMO lama gagal setelah commit: '.get_class($e));
            $report['files_cleanup_failed'] = true;
        }

        return $report;
    }

    /**
     * Titik sambung untuk test (dipanggil di dalam transaksi, sesudah
     * penyalinan). Produksi tidak mengisinya.
     */
    protected function afterClone(): void {}

    /** @return array<string,array{0:string,1:string}> "disk|path" => [disk, path] milik baris DEMO (sebelum dihapus) */
    private function demoFiles(): array
    {
        $out = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach ($spec['files'] as $column => $disk) {
                $paths = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))
                    ->whereNotNull($column)->where($column, '!=', '')->pluck($column);

                foreach ($paths as $path) {
                    $out["{$disk}|{$path}"] = [$disk, (string) $path];
                }
            }
        }

        return $out;
    }

    /**
     * Berkas DEMO lama dihapus hanya bila TIDAK ada baris (mode apa pun) yang
     * masih menunjuknya — melindungi berkas LIVE kalau sisa kloning lama atau
     * seeder pernah membuat baris DEMO menunjuk path LIVE. protected sebagai
     * titik sambung test untuk kegagalan sesudah commit.
     */
    protected function deleteUnreferencedFiles(array $stale): void
    {
        foreach ($stale as [$disk, $path]) {
            if (! $this->isReferenced($disk, $path)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    private function isReferenced(string $disk, string $path): bool
    {
        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach ($spec['files'] as $column => $fileDisk) {
                if ($fileDisk === $disk && DB::table($table)->where($column, $path)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function verifyIntegrity(): void
    {
        foreach (CloneRegistry::tables() as $table => $spec) {
            $live = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->count();
            $demo = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))->count();
            $skipped = $this->skipped[$table] ?? 0;

            if ($demo !== $live - $skipped) {
                throw new CloneException("{$table}: jumlah baris DEMO ({$demo}) tidak sama dengan LIVE yang disalin (".($live - $skipped).').');
            }

            foreach (CloneRegistry::references($table) as $column => $parent) {
                foreach ([['demo', 'live'], ['live', 'demo']] as [$childMode, $parentMode]) {
                    $bad = $this->crossModeCount($table, $column, $parent, $childMode, $parentMode);

                    if ($bad > 0) {
                        throw new CloneException("{$table}.{$column}: {$bad} baris {$childMode} menunjuk induk {$parentMode} (lintas mode).");
                    }
                }
            }

            if ($skipped === 0) {
                foreach ($this->decimalColumns($table) as $column) {
                    $liveSum = (string) DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->selectRaw("SUM(`{$column}`) AS s")->value('s');
                    $demoSum = (string) DB::table($table)->whereRaw(CloneRegistry::scope($table, 'demo'))->selectRaw("SUM(`{$column}`) AS s")->value('s');

                    if ($liveSum !== $demoSum) {
                        throw new CloneException("{$table}.{$column}: total DEMO ({$demoSum}) tidak sama dengan LIVE ({$liveSum}).");
                    }
                }
            }
        }

        // Stok: salinan tidak boleh mengubah hubungan current_stock ↔ riwayat
        // (kalau LIVE sendiri tidak konsisten, DEMO harus sama persis, bukan "diperbaiki").
        $mismatch = fn (string $mode) => (int) DB::scalar(
            "SELECT COUNT(*) FROM product_variants v WHERE v.data_mode = '{$mode}' AND v.current_stock <> COALESCE((SELECT SUM(m.qty_change) FROM stock_movements m WHERE m.variant_id = v.id), 0)"
        );

        if ($mismatch('live') !== $mismatch('demo')) {
            throw new CloneException('Ketidakcocokan stok DEMO berbeda dari LIVE.');
        }
    }

    /** Setiap path berkas DEMO ada di disknya dan tidak sama dengan path LIVE mana pun. */
    private function verifyFiles(): void
    {
        $live = $this->filePaths('live');

        foreach ($this->filePaths('demo') as $disk => $set) {
            foreach (array_keys($set) as $path) {
                $path = (string) $path;

                if (isset($live[$disk][$path])) {
                    throw new CloneException("Berkas di disk {$disk} dipakai bersama oleh baris LIVE dan DEMO.");
                }

                if (! Storage::disk($disk)->exists($path)) {
                    throw new CloneException("Berkas DEMO di disk {$disk} tidak ditemukan sesudah disalin.");
                }
            }
        }
    }

    /** @return array<string,array<string,true>> disk => [path => true] untuk satu mode */
    private function filePaths(string $mode): array
    {
        $out = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach ($spec['files'] as $column => $disk) {
                $paths = DB::table($table)->whereRaw(CloneRegistry::scope($table, $mode))
                    ->whereNotNull($column)->where($column, '!=', '')->pluck($column);

                foreach ($paths as $path) {
                    $out[$disk][(string) $path] = true;
                }
            }
        }

        return $out;
    }

    /** Jumlah baris mode $childMode di $table yang kolom $column-nya menunjuk baris mode $parentMode di $parent. */
    private function crossModeCount(string $table, string $column, string $parent, string $childMode, string $parentMode): int
    {
        return DB::table($table)
            ->whereRaw(CloneRegistry::scope($table, $childMode))
            ->whereRaw("`{$column}` IN (SELECT id FROM `{$parent}` WHERE ".CloneRegistry::scope($parent, $parentMode).')')
            ->count();
    }

    /**
     * Dijalankan SEBELUM DEMO dihapus. FK dengan ON DELETE SET NULL / CASCADE
     * (orders.customer_id, preorders.event_id, payments.session_id,
     * product_variant_bom_lines.product_variant_id, session_opening_cash_entries.session_id, …)
     * akan DIAM-DIAM mengubah atau menghapus baris LIVE yang menunjuk baris DEMO
     * saat DEMO dihapus. Sidik jari LIVE memang akan menangkapnya lalu membatalkan,
     * tetapi dengan pesan umum; cek ini berhenti lebih awal dan menyebut tabel/kolom/jumlah.
     * pos_drafts tidak disalin, tapi event_id-nya SET NULL dari events, jadi ikut dicek.
     */
    private function assertNoLiveReferencesToDemo(): void
    {
        foreach (CloneRegistry::tables() as $table => $_) {
            foreach (CloneRegistry::references($table) as $column => $parent) {
                $count = $this->crossModeCount($table, $column, $parent, 'live', 'demo');

                if ($count > 0) {
                    throw new CloneException("{$table}.{$column}: {$count} baris LIVE menunjuk induk {$parent} milik DEMO; data lintas mode harus dibereskan dulu, tidak ada yang diubah.");
                }
            }
        }

        $drafts = DB::table('pos_drafts')
            ->where('data_mode', 'live')
            ->whereIn('event_id', DB::table('events')->where('data_mode', 'demo')->select('id'))
            ->count();

        if ($drafts > 0) {
            throw new CloneException("pos_drafts.event_id: {$drafts} baris LIVE menunjuk events milik DEMO; data lintas mode harus dibereskan dulu, tidak ada yang diubah.");
        }
    }

    /** @return list<string> */
    private function decimalColumns(string $table): array
    {
        return collect(Schema::getColumns($table))->where('type_name', 'decimal')->pluck('name')->all();
    }

    /** @return array<string,int> */
    private function wipeDemo(): array
    {
        $deleted = [];

        foreach (array_reverse(array_keys(CloneRegistry::tables())) as $table) {
            $where = CloneRegistry::scope($table, 'demo');

            // Self-FK (categories.parent_id = RESTRICT) bisa menahan DELETE
            // massal: lepas dulu — hanya pada baris DEMO.
            foreach (CloneRegistry::spec($table)['deferred'] as $column => $_) {
                DB::table($table)->whereRaw($where)->update([$column => null]);
            }

            $deleted[$table] = DB::table($table)->whereRaw($where)->delete();
        }

        return $deleted;
    }

    /** @return array<string,int> */
    private function cloneAll(FileCopier $copier, CodeAllocator $codes): array
    {
        $this->maps = [];
        $this->prefixes = [];
        $this->skipped = [];
        $this->referencesDropped = 0;
        $counts = [];

        foreach (CloneRegistry::tables() as $table => $spec) {
            $this->maps[$table] = [];
            $counts[$table] = 0;
            $deferred = [];

            $rows = DB::table($table)->whereRaw(CloneRegistry::scope($table, 'live'))->orderBy('id')->get();

            foreach ($rows as $row) {
                $oldId = (int) $row->id;
                $data = (array) $row;
                unset($data['id']);

                foreach ($spec['fks'] as $column => $parent) {
                    $data[$column] = $this->mapForeignKey($table, $column, $parent, $row->{$column}, $oldId);
                }

                foreach ($spec['deferred'] as $column => $parent) {
                    $deferred[$oldId][$column] = $row->{$column};
                    $data[$column] = null;
                }

                foreach ($spec['suffix'] as $column => $max) {
                    if ($data[$column] !== null) {
                        $data[$column] = $codes->suffixed((string) $data[$column], $max, "{$table}.{$column} id {$oldId}");
                    }
                }

                foreach ($spec['uuid'] as $column) {
                    if ($data[$column] !== null) {
                        $data[$column] = (string) Str::uuid();
                    }
                }

                $skip = false;

                foreach ($spec['files'] as $column => $disk) {
                    $data[$column] = $copier->copy($disk, $data[$column]);

                    if ($data[$column] === null && in_array($column, $spec['required_files'], true)) {
                        $skip = true;
                    }
                }

                if ($skip) {
                    $this->skipped[$table] = ($this->skipped[$table] ?? 0) + 1;

                    continue;
                }

                $data = $this->transformSpecial($table, $row, $data, $codes, $oldId);

                if (array_key_exists('data_mode', $data)) {
                    $data['data_mode'] = 'demo';
                }

                $this->maps[$table][$oldId] = DB::table($table)->insertGetId($data);
                $counts[$table]++;
            }

            foreach ($spec['deferred'] as $column => $parent) {
                foreach ($deferred as $oldId => $values) {
                    if ($values[$column] === null) {
                        continue;
                    }

                    $newParent = $this->maps[$parent][(int) $values[$column]] ?? null;

                    if ($newParent === null) {
                        throw new CloneException("{$table}.{$column} (id {$oldId}): induk {$parent} id {$values[$column]} bukan baris LIVE yang ikut disalin.");
                    }

                    // id baru = baris DEMO yang baru saja dibuat; LIVE tidak tersentuh.
                    DB::table($table)->where('id', $this->maps[$table][$oldId])->update([$column => $newParent]);
                }
            }
        }

        return $counts;
    }

    private function mapForeignKey(string $table, string $column, string $parent, mixed $value, int $oldId): ?int
    {
        if ($value === null) {
            return null;
        }

        $new = $this->maps[$parent][(int) $value] ?? null;

        if ($new === null) {
            throw new CloneException("{$table}.{$column} (id {$oldId}): induk {$parent} id {$value} bukan baris LIVE yang ikut disalin.");
        }

        return $new;
    }

    /** Aturan per tabel yang tidak bisa dinyatakan secara deklaratif di registry. */
    private function transformSpecial(string $table, object $row, array $data, CodeAllocator $codes, int $oldId): array
    {
        switch ($table) {
            case 'artists':
                $data['code'] = $codes->artist((string) $row->code);
                break;

            case 'categories':
                $data['code'] = $codes->category((string) $row->code);
                break;

            case 'products':
                $demoPrefix = $codes->productPrefix((string) $row->code_prefix, $oldId);
                $this->prefixes[$oldId] = [(string) $row->code_prefix, $demoPrefix];
                $data['code_prefix'] = $demoPrefix;
                break;

            case 'product_variants':
                [$livePrefix, $demoPrefix] = $this->prefixes[(int) $row->product_id]
                    ?? throw new CloneException("product_variants id {$oldId}: produk induk tidak ikut disalin.");
                $data['sku'] = $codes->sku((string) $row->sku, $livePrefix, $demoPrefix, $oldId);
                break;

            case 'stock_movements':
                $data['reference_id'] = $this->remapReference($row->reference_id, $this->stockReferenceParent($row));
                break;

            case 'material_stock_movements':
                $data['reference_id'] = $this->remapReference(
                    $row->reference_id,
                    $row->reference_type === 'purchase_order_item' ? 'purchase_order_items' : null,
                );
                break;
        }

        return $data;
    }

    /**
     * reference_id berarti hal berbeda per penulis (CLAUDE.md, fitur 036).
     * Kombinasi di luar daftar ini TIDAK disalin: id LIVE tidak boleh bocor
     * ke baris DEMO, jadi dijadikan NULL dan dihitung di laporan.
     */
    private function stockReferenceParent(object $row): ?string
    {
        return match (true) {
            $row->type === 'sale' && $row->reference_type === 'order_item' => 'orders',
            $row->type === 'return' && $row->reference_type === 'order_item' => 'order_items',
            in_array($row->type, ['purchase', 'preorder_handover'], true) && $row->reference_type === 'preorder_item' => 'preorder_items',
            $row->type === 'purchase' && $row->reference_type === 'preorder' => 'preorders',
            default => null,
        };
    }

    private function remapReference(mixed $referenceId, ?string $parent): ?int
    {
        if ($referenceId === null) {
            return null;
        }

        $new = $parent === null ? null : ($this->maps[$parent][(int) $referenceId] ?? null);

        if ($new === null) {
            $this->referencesDropped++;
        }

        return $new;
    }
}
