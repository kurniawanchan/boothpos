// 049 — geometri barcode Code 128. Polanya ("1" = batang, "0" = spasi) dihasilkan SERVER
// (App\Support\Code128), satu-satunya encoder; di sini hanya menggambarnya. Tidak ada encoder kedua
// di JavaScript yang bisa menyimpang dari yang dipakai Excel.

/** Zona hening minimum di kiri/kanan, dalam modul — pemindai butuh ruang putih ini. */
export const QUIET_ZONE_MODULES = 10;

/** Lebar modul terkecil yang masih andal terbaca pemindai biasa pada cetak A4 100% (mm). */
export const MIN_MODULE_MM = 0.25;

const MM_PER_CSS_PX = 25.4 / 96;

/** Skala rasterisasi halaman PDF (piksel perangkat per piksel CSS); dipakai bersama oleh pembuat PDF dan barcode. */
export const PDF_RASTER_SCALE = 2;

/**
 * Lebar modul dalam piksel PERANGKAT yang BULAT agar setiap batang jatuh tepat di batas piksel: modul 2,27 px
 * (lebar tetap / 275 modul) membuat batang berselang-seling 2 dan 3 px dan tepinya kabur — kode 20 karakter,
 * yang terpadat, gagal dibaca saat pola di PDF didekode ulang dari pikselnya (verifikasi browser 049).
 */
export function integerModulePx(pattern, maxCssWidth, scale = 1, quiet = QUIET_ZONE_MODULES) {
  return Math.max(1, Math.floor((maxCssWidth * scale) / totalModules(pattern, quiet)));
}

/** Gabungkan modul "1" berurutan jadi satu batang: [{ x, w }] (x sudah termasuk zona hening). */
export function barRuns(pattern, quiet = QUIET_ZONE_MODULES) {
  const runs = [];
  let start = -1;
  for (let i = 0; i <= pattern.length; i += 1) {
    const on = i < pattern.length && pattern[i] === '1';
    if (on && start < 0) start = i;
    if (!on && start >= 0) {
      runs.push({ x: start + quiet, w: i - start });
      start = -1;
    }
  }
  return runs;
}

/** Jumlah modul termasuk dua zona hening. */
export function totalModules(pattern, quiet = QUIET_ZONE_MODULES) {
  return pattern.length + 2 * quiet;
}

/** Lebar satu modul (mm) bila barcode digambar selebar `widthPx` piksel CSS pada cetak 100%. */
export function moduleWidthMm(pattern, widthPx, quiet = QUIET_ZONE_MODULES) {
  return (widthPx * MM_PER_CSS_PX) / totalModules(pattern, quiet);
}
