<?php

namespace App\Support;

/**
 * Aturan tunggal untuk segmen produk (spec 042, diperluas spec 046).
 *
 * Segmen adalah huruf A–Z, boleh dipisah SATU tanda hubung di antara kelompok
 * huruf (mis. "SH-K"), dengan panjang maksimal 6 karakter TERMASUK tanda hubung.
 * Disimpan apa adanya (huruf besar) dan TIDAK dipad — "S" menghasilkan kode
 * "SPF-SH-S" dan SKU "SPF-SH-S-1". Tanda hubung tidak boleh di awal, di akhir,
 * atau dua berturutan.
 *
 * Segmen kosong diturunkan dari nama produk oleh ProductCodeGenerator
 * (selalu 3 huruf, dipad 'X'); itu jalur terpisah dari aturan ini.
 *
 * Lebar kolom dan batas SKU juga tinggal di sini (spec 046): kode produk paling
 * panjang 13 karakter, SKU paling panjang 20. Jangan menulis angka ini di tempat lain.
 * Migration 2026_11_19_000001 melebarkan kolom sesuai konstanta ini.
 */
final class ProductSegment
{
    /** Panjang maksimal segmen, tanda hubung ikut dihitung. */
    public const MAX_LENGTH = 6;

    /** Kode produk "ART-CA-SEG" paling panjang: 3 + 1 + 2 + 1 + segmen. */
    public const MAX_CODE_LENGTH = 13;

    /** SKU paling panjang: kode produk + "-" + nomor (sampai 6 digit). */
    public const MAX_SKU_LENGTH = 20;

    /** Segmen saja: huruf, boleh satu tanda hubung di antara kelompok huruf, ≤ 6 karakter. */
    public const PATTERN = '/^(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$/';

    /**
     * Kode produk utuh "ART-CA-SEG". Artis (3) dan kategori (2) memakai panjang
     * tetap; hanya segmen yang bervariasi. Dipakai juga oleh klon DEMO setelah
     * nilai dibuat huruf besar.
     */
    public const CODE_PATTERN = '/^[A-Z]{3}-[A-Z]{2}-(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$/';

    /** Bentuk string untuk aturan `regex:` pada FormRequest (tanpa delimiter khusus). */
    public const PATTERN_RULE = '/^(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$/';

    /**
     * Membersihkan input segmen: buang spasi tepi, huruf besar. Kosong (atau
     * hanya spasi) → null, artinya "turunkan dari nama produk".
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }
}
