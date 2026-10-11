<?php

namespace App\Services\DemoClone;

use Illuminate\Support\Facades\DB;
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
     * Seluruhnya dalam SATU transaksi; salinan berkas yang sudah dibuat
     * dihapus bila transaksi gagal.
     */
    public function run(): array
    {
        $copier = new FileCopier;

        try {
            return DB::transaction(function () use ($copier) {
                $deleted = $this->wipeDemo();
                $codes = new CodeAllocator;
                $cloned = $this->cloneAll($copier, $codes);

                $this->afterClone();

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
            $copier->discardCopies();

            throw $e;
        }
    }

    /**
     * Titik sambung untuk test (dipanggil di dalam transaksi, sesudah
     * penyalinan). Produksi tidak mengisinya.
     */
    protected function afterClone(): void {}

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
