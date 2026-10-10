import { getCurrentScope, onScopeDispose, ref } from 'vue';

/**
 * 028 — jam reaktif untuk menampilkan "sudah menunggu N menit" tanpa meminta data
 * baru ke server (lama menunggu sengaja tidak ikut token versi umpan dapur).
 * Berhenti otomatis bersama scope komponen.
 */
export function useNow(intervalMs = 15000) {
  const now = ref(Date.now());
  const timer = setInterval(() => { now.value = Date.now(); }, intervalMs);
  if (getCurrentScope()) onScopeDispose(() => clearInterval(timer));
  return now;
}
