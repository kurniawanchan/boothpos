<?php

namespace App\Support;

/**
 * 049-product-variant-catalog-export — SATU-SATUNYA encoder Code 128 aplikasi.
 *
 * Kenapa Code 128 (set B): hampir semua pemindai (genggam, meja kasir, aplikasi ponsel)
 * membacanya, dan ia membawa seluruh ASCII cetak — huruf, angka, tanda hubung — sedangkan
 * EAN/UPC hanya angka dengan panjang tetap dan Code 39 tak punya huruf kecil. SKU varian
 * seperti `SPF-AP-MCPS-S-32` (sampai 20 karakter) harus terbaca persis sama dengan yang
 * dicari POS, jadi satu set (B) tanpa peralihan ke set C: lebih panjang sedikit pada barisan
 * angka, tetapi polanya sederhana dan mudah dibuktikan benar.
 *
 * Kenapa hanya menghasilkan POLA modul ("1" = batang, "0" = spasi): API katalog mengirim pola
 * ini; SPA menggambarnya sebagai batang vektor yang tajam, sedangkan Excel merendernya jadi PNG
 * lewat png() di bawah. Satu encoder, dua penggambar — tidak ada encoder kedua di JavaScript
 * yang bisa menyimpang. Kebenaran tabel dibuktikan terhadap library picqer (require-dev saja,
 * tidak ikut dikirim ke toko; image toko memakai --no-dev) di tests/Unit/Code128Test.php.
 */
class Code128
{
    /**
     * Tabel simbol resmi: nilai simbol 0–106 -> lebar batang/spasi bergantian (mulai dari batang),
     * 6 elemen per simbol (11 modul), kecuali Stop (106) yang 7 elemen (13 modul).
     */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * Pola modul untuk $value, atau null bila tidak bisa dibawa Code 128 set B (kosong, karakter
     * kontrol, DEL, atau di luar ASCII — termasuk nama non-Latin). Pemanggil tetap menampilkan
     * kode terbacanya; kegagalan satu kode tidak boleh menggagalkan seluruh katalog.
     */
    public static function pattern(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $values = [];
        foreach (str_split($value) as $char) { // per BYTE: karakter multibita punya byte >= 128 -> ditolak
            $ord = ord($char);
            if ($ord < 32 || $ord > 126) {
                return null;
            }
            $values[] = $ord - 32;
        }

        $checksum = self::START_B;
        foreach ($values as $i => $v) {
            $checksum += $v * ($i + 1);
        }

        $pattern = '';
        foreach ([self::START_B, ...$values, $checksum % 103, self::STOP] as $symbol) {
            $pattern .= self::modules(self::PATTERNS[$symbol]);
        }

        return $pattern;
    }

    /**
     * PNG hitam di atas putih untuk Excel. Selalu gelap-di-atas-putih dengan zona hening putih
     * (>= 10 modul) — pemindai membutuhkan kontras itu, jangan diganti warna aksen.
     */
    public static function png(string $pattern, int $moduleWidth = 2, int $height = 60, int $quietZoneModules = 10): string
    {
        $moduleWidth = max(1, $moduleWidth);
        $quiet = max(0, $quietZoneModules);
        $image = imagecreatetruecolor((strlen($pattern) + 2 * $quiet) * $moduleWidth, max(1, $height));
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, imagesx($image) - 1, imagesy($image) - 1, $white);

        foreach (str_split($pattern) as $i => $module) {
            if ($module === '1') {
                $x = ($quiet + $i) * $moduleWidth;
                imagefilledrectangle($image, $x, 0, $x + $moduleWidth - 1, imagesy($image) - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /** "211214" -> "11 0 1 00 1 0000"-gaya: lebar bergantian batang/spasi, dimulai dari batang. */
    private static function modules(string $widths): string
    {
        $out = '';
        $bar = true;
        foreach (str_split($widths) as $width) {
            $out .= str_repeat($bar ? '1' : '0', (int) $width);
            $bar = ! $bar;
        }

        return $out;
    }
}
