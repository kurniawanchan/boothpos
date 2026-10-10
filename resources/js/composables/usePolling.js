import { getCurrentScope, onScopeDispose, ref } from 'vue';

const MAX_BACKOFF_MS = 15000;

/**
 * 028 — polling pendek untuk layar lantai/dapur (research.md Keputusan 6).
 * SENGAJA polling, bukan SSE/websocket: kedua image Docker menjalankan PHP
 * lewat `artisan serve`, jadi satu koneksi yang dibiarkan terbuka akan
 * menahan satu worker PHP.
 *
 * Perilaku yang dijamin (agar layar yang dibiarkan semalaman tidak basi):
 *  - berhenti saat tab/jendela tersembunyi; saat tampil kembali langsung
 *    mengambil data (bukan menunggu interval berikutnya) dengan `full: true`;
 *  - tidak pernah menjalankan dua permintaan bersamaan (in-flight guard);
 *  - setelah galat (koneksi putus), pemanggilan berikutnya `full: true` —
 *    pemanggil membuang token versi lamanya dan mengambil keadaan terkini —
 *    dengan jeda yang membesar (exponential, maks 15 dtk) lalu pulih sendiri;
 *  - berhenti otomatis saat scope komponen dibuang.
 *
 * @param {(ctx: { full: boolean }) => Promise<unknown>} fetchFn
 * @param {{ interval?: number, jitter?: number, immediate?: boolean }} [opts] immediate=false → pengambilan
 *   pertama menunggu satu interval (dipakai layar yang baru saja memuat datanya sendiri)
 */
export function usePolling(fetchFn, { interval = 3000, jitter = 250, immediate = true } = {}) {
  const running = ref(false);
  const lastError = ref(null);

  let timer = null;
  let inFlight = false;
  let needFull = true;
  let failures = 0;
  let again = false; // refresh() diminta saat permintaan lain sedang berjalan

  const isHidden = () => typeof document !== 'undefined' && document.hidden;
  const delay = () => Math.min(interval * 2 ** failures, MAX_BACKOFF_MS) + Math.floor(Math.random() * jitter);

  function schedule() {
    clearTimeout(timer);
    if (running.value && !isHidden()) timer = setTimeout(tick, delay());
  }

  async function tick() {
    clearTimeout(timer);
    if (!running.value || isHidden()) return;
    if (inFlight) {
      again = true;
      return;
    }

    inFlight = true;
    try {
      await fetchFn({ full: needFull });
      needFull = false;
      failures = 0;
      lastError.value = null;
    } catch (err) {
      needFull = true;
      failures += 1;
      lastError.value = err;
    } finally {
      inFlight = false;
      if (again) {
        again = false;
        tick();
      } else {
        schedule();
      }
    }
  }

  function onVisibilityChange() {
    if (!running.value) return;
    if (isHidden()) {
      clearTimeout(timer);
    } else {
      needFull = true;
      tick();
    }
  }

  function start() {
    if (running.value) return;
    running.value = true;
    needFull = true;
    failures = 0;
    document.addEventListener('visibilitychange', onVisibilityChange);
    if (immediate) tick();
    else schedule();
  }

  function stop() {
    running.value = false;
    clearTimeout(timer);
    document.removeEventListener('visibilitychange', onVisibilityChange);
  }

  /** Ambil sekarang juga (mis. setelah aksi pengguna), tanpa menumpuk permintaan. */
  function refresh() {
    if (!running.value) return Promise.resolve();
    return tick();
  }

  if (getCurrentScope()) onScopeDispose(stop);

  return { start, stop, refresh, running, lastError };
}
