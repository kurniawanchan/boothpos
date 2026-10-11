<?php

namespace App\Services\DemoClone;

use Illuminate\Support\Facades\DB;

/**
 * Kode unik untuk baris DEMO hasil salinan. Lebar kolom menentukan aturan:
 * artists.code = char(3) dan categories.code = char(2), jadi akhiran "-DM"
 * tidak muat. Keduanya DIALOKASIKAN dari kode yang belum dipakai (lintas
 * mode, termasuk soft-deleted — UNIQUE DB tidak peduli deleted_at), lalu
 * code_prefix produk ("AAA-BB-CCC") dan SKU ("AAA-BB-CCC-NNN") disusun
 * ulang dari pemetaan itu supaya bentuknya tetap valid.
 *
 * Bangun SESUDAH data DEMO lama dihapus: kode milik DEMO lama sudah bebas.
 */
final class CodeAllocator
{
    private const LETTERS = ['Z', 'Y', 'X', 'W', 'V', 'U', 'T', 'S', 'R', 'Q', 'P', 'O', 'N',
        'M', 'L', 'K', 'J', 'I', 'H', 'G', 'F', 'E', 'D', 'C', 'B', 'A'];

    /** @var array<string,array<string,string>> */
    private array $map = ['artists' => [], 'categories' => []];

    /** @var array<string,array<string,true>> */
    private array $used = ['artists' => [], 'categories' => []];

    public function __construct()
    {
        foreach (['artists', 'categories'] as $table) {
            foreach (DB::table($table)->pluck('code') as $code) {
                $this->used[$table][strtoupper((string) $code)] = true;
            }
        }
    }

    public function artist(string $liveCode): string
    {
        return $this->allocate('artists', $liveCode, 3);
    }

    public function category(string $liveCode): string
    {
        return $this->allocate('categories', $liveCode, 2);
    }

    public function productPrefix(string $livePrefix, int $productId): string
    {
        if (! preg_match('/^([A-Za-z]{3})-([A-Za-z]{2})-([A-Za-z]{3})$/', $livePrefix, $m)) {
            throw new CloneException("products id {$productId}: code_prefix tidak berbentuk AAA-BB-CCC.");
        }

        // Tiap bagian prefix dicari lewat peta kode LIVE-nya sendiri (bukan lewat
        // artist/kategori produk sekarang): kode bersifat permanen (F19.4), jadi
        // artist/kategori produk bisa saja sudah berganti setelah prefix dibuat.
        return $this->artist($m[1]).'-'.$this->category($m[2]).'-'.strtoupper($m[3]);
    }

    public function sku(string $liveSku, string $livePrefix, string $demoPrefix, int $variantId): string
    {
        if (! str_starts_with($liveSku, $livePrefix.'-')) {
            throw new CloneException("product_variants id {$variantId}: sku tidak diawali code_prefix produknya.");
        }

        return $demoPrefix.substr($liveSku, strlen($livePrefix));
    }

    public function suffixed(string $value, int $max, string $where): string
    {
        $out = $value.'-DM';

        if (strlen($out) > $max) {
            throw new CloneException("{$where}: terlalu panjang untuk akhiran -DM (maks {$max}).");
        }

        return $out;
    }

    /** @return array{artists:array<string,string>,categories:array<string,string>} */
    public function mapping(): array
    {
        return $this->map;
    }

    private function allocate(string $table, string $liveCode, int $width): string
    {
        $key = strtoupper($liveCode);

        if (isset($this->map[$table][$key])) {
            return $this->map[$table][$key];
        }

        $tail = substr($key, 0, $width - 1);

        foreach (self::LETTERS as $letter) {
            $candidate = $letter.$tail;

            if (! isset($this->used[$table][$candidate])) {
                $this->used[$table][$candidate] = true;

                return $this->map[$table][$key] = $candidate;
            }
        }

        throw new CloneException("Tidak ada kode {$table} kosong untuk menggantikan {$key}.");
    }
}
