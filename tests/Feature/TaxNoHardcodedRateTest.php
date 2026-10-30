<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 085 / US1 (FR-017) — tidak boleh ada tarif pajak tertulis di kode yang memengaruhi tagihan. Pola yang sama dengan tes
 * pemindai sumber `outletId` di StockService: pemindaian murni atas berkas, tanpa DB. Tarif HANYA boleh datang dari
 * Pengaturan (`PosSettings::taxRate()`) di server, dan dari server di web/Android. Cadangan 10% di Android pernah ada
 * (dihapus di 083) dan tidak ada tes yang akan menangkapnya kembali.
 *
 * Yang dipindai: baris KODE (bukan komentar) yang menyebut pajak DAN memuat pengali/persen tertulis.
 */
class TaxNoHardcodedRateTest extends TestCase
{
    /** Kata yang menandai baris sebagai baris pajak. */
    private const TAX_WORD = '/\b(tax|pajak|ppn|vat)\w*/i';

    /** Pengali, pembagi, atau persen tertulis: `* 0.1`, `* 0.11`, `* 1.1`, `/ 11`, `* 10 / 100`, `10%`, `11 %`. */
    private const LITERAL_RATE = '/(\*\s*0?\.\d+|\*\s*1\.\d+|[*\/]\s*1[01]\b|\b1[01](\.0+)?\s*%|=\s*0?\.1[01]?\b\s*;)/';

    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $trees = [
            [$root.'/app', ['php']],
            [$root.'/resources/js', ['js', 'vue']],
            [$root.'/android-pos/app/src/main', ['kt']],
        ];
        $out = [];
        foreach ($trees as [$dir, $exts]) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && in_array($f->getExtension(), $exts, true)) {
                    $out[] = $f->getPathname();
                }
            }
        }

        return $out;
    }

    /** Baris kode tanpa komentar satu baris di ujung; baris yang seluruhnya komentar dilewati (null). */
    private function code(string $line): ?string
    {
        $t = ltrim($line);
        foreach (['//', '*', '/*', '#', '<!--', '@'] as $c) {
            if (str_starts_with($t, $c)) {
                return null;
            }
        }

        return preg_replace('#\s//.*$#', '', $line);
    }

    public function test_no_tax_rate_is_written_into_code(): void
    {
        $root = dirname(__DIR__, 2).'/';
        $hits = [];
        foreach ($this->files() as $file) {
            foreach (file($file) as $i => $raw) {
                $line = $this->code($raw);
                if ($line !== null && preg_match(self::TAX_WORD, $line) && preg_match(self::LITERAL_RATE, $line)) {
                    $hits[] = str_replace($root, '', $file).':'.($i + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $hits, "Tarif pajak tertulis di kode (harus dari Pengaturan/server):\n".implode("\n", $hits));
    }

    /** Tes ini menjaga pemindainya sendiri: pola harus benar-benar menangkap cadangan 10% gaya lama. */
    public function test_the_scanner_catches_the_old_fallback_shapes(): void
    {
        foreach ([
            'val tax: Int get() = serverTax ?: (base * 0.10).roundToInt()',
            'val tax = (subtotal * 0.11).toInt()',
            'return $tax = $base * 1.1;',
            'const taxAmount = base / 10',
            'val ppn = 10 %',
        ] as $bad) {
            $this->assertSame(1, preg_match(self::TAX_WORD, $bad), $bad);
            $this->assertSame(1, preg_match(self::LITERAL_RATE, $bad), $bad);
        }
        foreach ([
            '$tax = round($base * $rate / 100, 0);',
            'taxRate: 0,',
            'val tax: Int get() = serverTax ?: 0',
        ] as $ok) {
            $this->assertSame(0, preg_match(self::LITERAL_RATE, $ok), $ok);
        }
    }
}
