import { getCurrentScope, onScopeDispose, ref } from 'vue';

/**
 * 042-dashboard-revamp (research D12) — memanggil `fn` tiap `intervalMs` selama tab terlihat. Sengaja lebih
 * sederhana daripada usePolling (028): Dashboard tidak butuh backoff atau token versi — kegagalan satu putaran
 * ditangani wilayahnya sendiri (ErrorState + Coba lagi), putaran berikutnya tetap jalan.
 *
 * Perilaku yang dijamin:
 *  - berhenti saat `document.hidden` (tab di latar tak membakar permintaan); saat tampil lagi langsung memanggil
 *    `fn` sekali (data yang basi tidak menunggu interval) lalu melanjutkan jadwal;
 *  - tidak pernah menumpuk panggilan: bila `fn` masih berjalan, tick berikutnya dilewati;
 *  - `running` hanya true selama pewaktu benar-benar jalan — penanda "Real-time" bergantung padanya;
 *  - dibersihkan otomatis saat scope komponen dibuang.
 *
 * @param {() => Promise<unknown>|unknown} fn
 * @param {number} intervalMs
 */
export function useAutoRefresh(fn, intervalMs = 30_000) {
  const running = ref(false);
  let timer = null;
  let inFlight = false;
  let started = false;

  const isHidden = () => typeof document !== 'undefined' && document.hidden;

  async function run() {
    if (inFlight) return;
    inFlight = true;
    try {
      await fn();
    } catch {
      // Galat dilaporkan oleh pemanggil di wilayahnya sendiri; pewaktu tidak boleh mati karenanya.
    } finally {
      inFlight = false;
    }
  }

  function arm() {
    clearInterval(timer);
    timer = setInterval(run, intervalMs);
    running.value = true;
  }

  function disarm() {
    clearInterval(timer);
    timer = null;
    running.value = false;
  }

  function onVisibility() {
    if (!started) return;
    if (isHidden()) {
      disarm();
    } else {
      run();
      arm();
    }
  }

  function start() {
    if (started) return;
    started = true;
    document.addEventListener('visibilitychange', onVisibility);
    if (!isHidden()) arm();
  }

  function stop() {
    started = false;
    disarm();
    document.removeEventListener('visibilitychange', onVisibility);
  }

  if (getCurrentScope()) onScopeDispose(stop);

  return { start, stop, running };
}
