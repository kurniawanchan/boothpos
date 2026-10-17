/**
 * Pratinjau segmen produk di formulir (spec 042).
 *
 * Salinan TAMPILAN dari aturan server (App\Support\ProductSegment). Server tetap
 * sumber kebenaran: yang disimpan selalu hasil validasi PHP, bukan pratinjau ini.
 *
 * - Segmen yang diketik: huruf besar, hanya huruf dan tanda hubung, dipotong ke
 *   6 karakter (spec 046). TIDAK dipad. "S" tampil sebagai "S" (SKU "SPF-SH-S-1");
 *   "SH-K" tampil utuh (SKU "SPF-SH-SH-K-1").
 * - Segmen kosong: diturunkan dari nama produk, selalu 3 huruf (dipad 'X'),
 *   seperti sebelumnya.
 */

/** Tiga huruf dari nama produk, dipad 'X' bila kurang. */
export function deriveSegment(name) {
  const letters = (name || '').replace(/[^A-Za-z]/g, '').toUpperCase();
  return letters.slice(0, 3).padEnd(3, 'X');
}

/** Segmen yang akan tampil di pratinjau kode untuk input form saat ini. */
export function previewSegment(typed, name) {
  const cleaned = (typed ?? '').trim().toUpperCase().replace(/[^A-Z-]/g, '').slice(0, 6);
  if (cleaned !== '') {
    return cleaned;
  }
  return deriveSegment(name);
}

/**
 * Pratinjau SKU variant pertama (spec 044): kode produk + tanda hubung + nomor 1
 * tanpa nol di depan. Salinan tampilan dari format server
 * (ProductCodeGenerator::variantSku).
 */
export function previewSku(artistCode, categoryCode, segment) {
  return `${artistCode}-${categoryCode}-${segment}-1`;
}

/**
 * Pisahkan SKU menjadi kode (tetap) dan nomor urut (diketik) — spec 045. Kode
 * adalah teks sebelum tanda hubung terakhir; SKU tanpa tanda hubung (lama) tidak
 * punya nomor yang bisa diketik.
 */
export function splitSku(sku) {
  const text = sku ?? '';
  const at = text.lastIndexOf('-');
  if (at < 0) {
    return { code: '', number: '' };
  }

  return { code: text.slice(0, at), number: text.slice(at + 1) };
}

/** Panjang maksimal nomor urut agar SKU lengkap tetap ≤ 20 karakter (spec 046, lebar kolom sku). */
export function maxSequenceLength(code) {
  return Math.max(1, 20 - (code ?? '').length - 1);
}

/**
 * Urutan SKU di formulir produk (spec 045, FR-012): kode dulu (teks), lalu nomor
 * sebagai bilangan bulat (`…-2` sebelum `…-10`). SKU tanpa tanda hubung (format
 * lama) tidak punya nomor dan diletakkan setelah SKU bernomor dengan kode yang sama.
 */
export function compareVariantSku(a, b) {
  const left = splitSku(a);
  const right = splitSku(b);
  const leftNumbered = left.number !== '' && /^\d+$/.test(left.number);
  const rightNumbered = right.number !== '' && /^\d+$/.test(right.number);

  if (leftNumbered && rightNumbered) {
    const byCode = left.code.localeCompare(right.code);
    if (byCode !== 0) return byCode;
    return Number(left.number) - Number(right.number);
  }
  if (leftNumbered !== rightNumbered) {
    // Yang bernomor didahulukan, kecuali kodenya berbeda (urutan kode tetap utama).
    const byCode = (leftNumbered ? left.code : a).localeCompare(rightNumbered ? right.code : b);
    if (byCode !== 0) return byCode;
    return leftNumbered ? -1 : 1;
  }
  return String(a ?? '').localeCompare(String(b ?? ''));
}
