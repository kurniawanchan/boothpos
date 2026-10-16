<?php

namespace App\Support;

/**
 * Aturan tunggal untuk segmen produk (spec 042).
 *
 * Segmen adalah 1–3 huruf (A–Z), disimpan apa adanya (huruf besar) dan TIDAK
 * dipad ke 3 karakter — "S" menghasilkan kode "SPF-SH-S" dan SKU "SPF-SH-S-001".
 * Segmen kosong diturunkan dari nama produk oleh ProductCodeGenerator
 * (selalu 3 huruf, dipad 'X'); itu jalur terpisah dari aturan ini.
 *
 * Sebelumnya aturan ini tersebar di lima tempat (FormRequest `size:3`,
 * generator `strlen === 10`, impor `harus tepat 3 huruf`, pratinjau SPA, dan
 * regex klon DEMO `[A-Za-z]{3}`) dan sudah saling berbeda. Semuanya kini
 * merujuk kelas ini; jangan menyalin polanya ke tempat lain.
 */
final class ProductSegment
{
    /** Segmen saja: 1–3 huruf A–Z (setelah normalise). */
    public const PATTERN = '/^[A-Z]{1,3}$/';

    /**
     * Kode produk utuh "ART-CA-SEG". Artis (3) dan kategori (2) memakai panjang
     * tetap; hanya segmen yang bervariasi. Dipakai juga oleh klon DEMO setelah
     * nilai dibuat huruf besar.
     */
    public const CODE_PATTERN = '/^[A-Z]{3}-[A-Z]{2}-[A-Z]{1,3}$/';

    /** Bentuk string untuk aturan `regex:` pada FormRequest (tanpa delimiter khusus). */
    public const PATTERN_RULE = '/^[A-Z]{1,3}$/';

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
