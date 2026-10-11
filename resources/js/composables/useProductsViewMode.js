/**
 * 043 — pilihan tampilan daftar Produk (Grid | Tabel) diingat PER PENGGUNA di perangkat ini, seperti useRailState:
 * preferensi tampilan murni (bukan data akun), jadi di localStorage, tidak lewat API. Baca/tulis dibungkus try/catch
 * karena localStorage bisa melempar di mode privat; bila gagal, daftar cukup tampil sebagai Grid (bawaan desain).
 * Alamat URL (?view=) tetap menang atas nilai tersimpan — tautan yang dibagikan harus tampil sama untuk semua orang.
 */
const VIEWS = ['grid', 'table'];
const keyFor = (userId) => `boothpos:products-view:${userId ?? 'anon'}`;

export function readStoredView(userId) {
  try {
    const stored = localStorage.getItem(keyFor(userId));
    return VIEWS.includes(stored) ? stored : 'grid';
  } catch {
    return 'grid';
  }
}

export function writeStoredView(userId, view) {
  if (!VIEWS.includes(view)) return;
  try {
    localStorage.setItem(keyFor(userId), view);
  } catch {
    // Non-fatal — preferensi cukup tidak tersimpan lintas sesi.
  }
}

export const VIEW_MODES = VIEWS;
