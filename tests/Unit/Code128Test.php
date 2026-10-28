<?php

namespace Tests\Unit;

use App\Support\Code128;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Picqer\Barcode\Types\TypeCode128B;

/**
 * 049 — satu-satunya encoder Code 128 aplikasi (SPA menggambar pola yang sama, Excel
 * merendernya jadi PNG). Tabel simbolnya ditulis sendiri; library picqer (require-dev,
 * TIDAK ikut dikirim ke toko karena image toko memakai --no-dev) hanya jadi PEMBANDING
 * independen supaya satu digit salah di tabel tidak lolos.
 */
class Code128Test extends TestCase
{
    private const START_B = '11010010000';

    private const STOP = '1100011101011';

    /** @return array<string, array{string}> */
    public static function codes(): array
    {
        return [
            'sku nyata' => ['SPF-AP-MCPS-S-32'],
            'huruf kecil' => ['abc-xyz'],
            'angka saja' => ['0123456789'],
            'satu karakter' => ['A'],
            'spasi dan tilde' => ['A ~z'],
            'sembilan belas karakter' => ['SPFABC-KC-MCYTAB-12'],
            'segmen enam huruf' => ['ABCDEF-KC-ABCDEF-100'],
            'semua ASCII cetak' => [implode('', array_map('chr', range(32, 126)))],
        ];
    }

    #[DataProvider('codes')]
    public function test_pattern_matches_the_reference_library_for_code_set_b(string $code): void
    {
        $reference = '';
        foreach ((new TypeCode128B)->getBarcode($code)->getBars() as $bar) {
            $reference .= str_repeat($bar->isBar() ? '1' : '0', $bar->getWidth());
        }

        $this->assertSame($reference, Code128::pattern($code));
    }

    #[DataProvider('codes')]
    public function test_pattern_has_start_b_stop_and_the_exact_module_count(string $code): void
    {
        $pattern = Code128::pattern($code);

        $this->assertStringStartsWith(self::START_B, $pattern);
        $this->assertStringEndsWith(self::STOP, $pattern);
        $this->assertSame(11 * (strlen($code) + 2) + 13, strlen($pattern)); // start + data + checksum + stop
    }

    #[DataProvider('codes')]
    public function test_a_reader_decodes_the_pattern_back_to_the_exact_code_with_a_valid_checksum(string $code): void
    {
        $this->assertSame($code, $this->decode(Code128::pattern($code)));
    }

    /** Spec 046: kode produk <= 13 karakter, SKU varian <= 20 — setiap panjang 1..20 dari huruf/angka/tanda hubung. */
    public function test_every_sku_length_up_to_the_maximum_round_trips_and_matches_the_reference(): void
    {
        $charset = array_merge(range('A', 'Z'), range('0', '9'), ['-']);
        mt_srand(49);
        foreach (range(1, 20) as $length) {
            $sku = '';
            for ($i = 0; $i < $length; $i++) {
                $sku .= $charset[mt_rand(0, count($charset) - 1)];
            }
            $pattern = Code128::pattern($sku);

            $this->assertSame($sku, $this->decode($pattern), "panjang {$length}");
            $this->assertSame(11 * ($length + 2) + 13, strlen($pattern));
            $reference = '';
            foreach ((new TypeCode128B)->getBarcode($sku)->getBars() as $bar) {
                $reference .= str_repeat($bar->isBar() ? '1' : '0', $bar->getWidth());
            }
            $this->assertSame($reference, $pattern, "panjang {$length} berbeda dari library pembanding");
        }
    }

    /** Pola terpanjang (20 karakter) = 255 modul; angka ini menjadi dasar lebar modul minimum di kartu PDF (BarcodeBars). */
    public function test_the_longest_sku_is_255_modules(): void
    {
        $this->assertSame(255, strlen(Code128::pattern(str_repeat('A', 20))));
    }

    public function test_it_refuses_what_code_128_b_cannot_carry(): void
    {
        $this->assertNull(Code128::pattern(''));
        $this->assertNull(Code128::pattern('café'));      // di luar ASCII
        $this->assertNull(Code128::pattern("a\tb"));      // karakter kontrol
        $this->assertNull(Code128::pattern('セラー-1'));    // nama non-Latin
        $this->assertNull(Code128::pattern(chr(127)));    // DEL
    }

    public function test_png_has_the_expected_pixel_size_and_is_a_real_png(): void
    {
        $pattern = Code128::pattern('SPF-KC-1');
        $png = Code128::png($pattern, moduleWidth: 2, height: 50, quietZoneModules: 10);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        [$w, $h] = getimagesizefromstring($png);
        $this->assertSame((strlen($pattern) + 20) * 2, $w);
        $this->assertSame(50, $h);
    }

    public function test_png_is_dark_bars_on_white_with_a_white_quiet_zone(): void
    {
        $pattern = Code128::pattern('A');
        $img = imagecreatefromstring(Code128::png($pattern, moduleWidth: 2, height: 20, quietZoneModules: 10));
        $white = 0xFFFFFF;

        $this->assertSame($white, imagecolorat($img, 0, 5) & 0xFFFFFF);                 // zona hening kiri
        $this->assertSame($white, imagecolorat($img, imagesx($img) - 1, 5) & 0xFFFFFF); // zona hening kanan
        $this->assertNotSame($white, imagecolorat($img, 10 * 2, 5) & 0xFFFFFF);         // modul pertama = batang gelap (Start B diawali bar)
    }

    /** Pembaca uji: memotong 11 modul per simbol, mencocokkannya ke tabel, dan memeriksa checksum. */
    private function decode(string $pattern): string
    {
        $symbols = array_flip(Code128::PATTERNS); // lebar-lebar -> nilai simbol
        $values = [];
        foreach (str_split(substr($pattern, 0, -13), 11) as $chunk) { // buang 13 modul stop
            preg_match_all('/(.)\1*/', $chunk, $runs);
            $widths = implode('', array_map('strlen', $runs[0]));
            $this->assertArrayHasKey($widths, $symbols, "simbol tak dikenal: {$widths}");
            $values[] = $symbols[$widths];
        }

        $start = array_shift($values);
        $checksum = array_pop($values);
        $this->assertSame(104, $start, 'harus Start Code B');

        $sum = $start;
        foreach ($values as $i => $v) {
            $sum += $v * ($i + 1);
        }
        $this->assertSame($sum % 103, $checksum, 'checksum salah');

        return implode('', array_map(fn (int $v) => chr($v + 32), $values));
    }
}
