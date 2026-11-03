// sessionStorage (not memory-only, not localStorage) per the project brief:
// memory-only forces re-login on every refresh; localStorage silently
// persists across browser restarts on a shared terminal. sessionStorage is
// the deliberate middle ground.
const TOKEN_KEY = 'boothpos_token';

export function getToken() {
  return sessionStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
  sessionStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
  sessionStorage.removeItem(TOKEN_KEY);
}

/**
 * 2026-11-02 — tab baru tidak perlu login ulang. sessionStorage itu per-tab, jadi tab baru (buka menu di tab baru, klik
 * tengah, salin tautan) selalu kosong. Alih-alih memindah token ke localStorage (bertahan setelah peramban ditutup, yang
 * justru dihindari keputusan di atas), tab baru MEMINTA token ke tab lain yang masih terbuka lewat BroadcastChannel
 * (hanya satu origin). Tak ada tab lain yang menjawab = tidak ada sesi terbuka = tetap minta login.
 */
const CHANNEL = 'boothpos_auth';

function openChannel() {
  return typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL);
}

/** Dipasang sekali saat aplikasi mulai: menjawab permintaan token dari tab baru bila tab ini punya sesi. */
export function listenForTokenRequests() {
  const channel = openChannel();
  if (!channel) return;
  channel.onmessage = (event) => {
    if (event.data?.type !== 'request') return;
    const token = getToken();
    if (token) channel.postMessage({ type: 'token', token });
  };
}

/** Meminta token ke tab lain; menyimpannya di tab ini bila ada yang menjawab dalam [timeoutMs]. */
export function adoptTokenFromOtherTabs(timeoutMs = 400) {
  return new Promise((resolve) => {
    const channel = openChannel();
    if (!channel) return resolve(false);
    const finish = (ok) => { clearTimeout(timer); channel.close(); resolve(ok); };
    const timer = setTimeout(() => finish(false), timeoutMs);
    channel.onmessage = (event) => {
      if (event.data?.type !== 'token' || !event.data.token) return;
      setToken(event.data.token);
      finish(true);
    };
    channel.postMessage({ type: 'request' });
  });
}
