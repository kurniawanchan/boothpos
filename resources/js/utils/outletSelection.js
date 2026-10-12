// 045 Stage 2 — pilihan outlet pengguna yang sedang aktif. Disimpan per id pengguna di localStorage
// supaya pilihan seorang kasir tidak "terbawa" ke akun lain di perangkat yang sama. Modul ini TIDAK
// mengambil keputusan izin: server yang menentukan outlet yang boleh dilihat (GET /me/scope), dan
// header X-Outlet-Id hanya menyempitkan dalam daftar itu. Klien yang meminta outlet di luar daftar
// akan menerima 403 dari server.

const STORAGE_PREFIX = 'boothpos.outlet.';

let active = { userId: null, outletId: null };

function storageKey(userId) {
  return `${STORAGE_PREFIX}${userId}`;
}

/** Mengaktifkan pengguna: memuat pilihan tersimpannya (atau null). Dipanggil saat login/pemuatan sesi. */
export function activateOutletSelection(userId) {
  active = { userId: userId ?? null, outletId: null };
  if (userId == null) return;
  const raw = globalThis.localStorage?.getItem(storageKey(userId));
  const parsed = raw == null ? null : Number.parseInt(raw, 10);
  active.outletId = Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

/** Menyimpan pilihan untuk pengguna aktif. `null` = semua outlet yang boleh dilihat. */
export function setSelectedOutlet(outletId) {
  active.outletId = outletId ?? null;
  if (active.userId == null) return;
  const key = storageKey(active.userId);
  if (active.outletId == null) {
    globalThis.localStorage?.removeItem(key);
  } else {
    globalThis.localStorage?.setItem(key, String(active.outletId));
  }
}

export function selectedOutletId() {
  return active.outletId;
}

/** Hapus pilihan dari memori (keluar dari sesi). Pilihan yang tersimpan per pengguna tetap ada. */
export function clearOutletSelection() {
  active = { userId: null, outletId: null };
}
