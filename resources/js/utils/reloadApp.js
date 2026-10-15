/**
 * Muat ulang aplikasi setelah database dipulihkan: sesi/token pengguna ikut
 * tertimpa isi cadangan, jadi pemuatan ulang menampilkan keadaan yang benar
 * (halaman login bila token tak ada di cadangan, data baru bila masih ada).
 * Ditunda sebentar supaya pesan sukses sempat terbaca. Dipisah jadi modul
 * sendiri agar test bisa menggantinya (jsdom tidak bisa reload).
 */
export function reloadApp(delayMs = 1500) {
  setTimeout(() => window.location.reload(), delayMs);
}
