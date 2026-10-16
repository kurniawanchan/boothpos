/**
 * Pratinjau segmen produk di formulir (spec 042).
 *
 * Salinan TAMPILAN dari aturan server (App\Support\ProductSegment). Server tetap
 * sumber kebenaran: yang disimpan selalu hasil validasi PHP, bukan pratinjau ini.
 *
 * - Segmen yang diketik: dipotong ke 3 huruf dan huruf besar, TIDAK dipad. "S"
 *   tampil sebagai "S" (SKU "SPF-SH-S-001").
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
  const cleaned = (typed ?? '').trim().toUpperCase().slice(0, 3);
  if (cleaned !== '') {
    return cleaned;
  }
  return deriveSegment(name);
}
