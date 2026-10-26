// 076-settlement-tab-session-branding (US1) — sesi lintas-tab.
//
// MASALAH: token login disimpan di sessionStorage (utils/storage.js), yang
// per-TAB. Tautan yang dibuka dengan klik tengah / Ctrl-klik / "Buka di tab
// baru" tidak mewarisinya, jadi tab baru selalu jatuh ke /login.
//
// SOLUSI: tab baru TANPA token bertanya lewat BroadcastChannel (hanya
// same-origin) ke tab lain yang sedang login; tab itu membalas dengan
// tokennya, dan tab baru menyimpannya di sessionStorage-nya SENDIRI.
//   - Token tidak pernah ditulis ke localStorage/cookie/URL, jadi menutup
//     seluruh jendela peramban tetap mengakhiri sesi (aturan terminal
//     bersama yang menjadi alasan sessionStorage dipilih).
//   - Token basi/dicabut ditolak oleh GET /auth/me (401) lalu dibersihkan
//     oleh auth.restore() seperti biasa — tidak ada jalur khusus.
//   - Tanpa BroadcastChannel (peramban lama) perilakunya sama persis dengan
//     sebelumnya: harus login. Bukan error.
//
// Logout juga disiarkan supaya semua tab ikut berakhir (FR-002).
import { getToken, setToken } from './storage';

const CHANNEL_NAME = 'boothpos-session';

/** Lama menunggu balasan tab lain; tab yang login membalas dalam hitungan ms. */
export const HANDOFF_TIMEOUT_MS = 250;

function openChannel() {
  if (typeof BroadcastChannel === 'undefined') return null;
  try {
    return new BroadcastChannel(CHANNEL_NAME);
  } catch {
    return null;
  }
}

// Satu kanal jangka panjang per tab untuk MENERIMA permintaan token & logout
// dan untuk MENGIRIM logout (undefined = belum dibuka, null = tidak didukung).
let shared;

function sharedChannel() {
  if (shared === undefined) shared = openChannel();
  return shared;
}

/** Hanya untuk test: lepas kanal bersama. */
export function resetTabSession() {
  shared?.close?.();
  shared = undefined;
}

/**
 * Dipasang sekali saat boot (main.js). Tab yang MEMEGANG token menjawab
 * permintaan tab lain; tab tanpa token diam. `onLogout` dipanggil saat tab
 * lain mengakhiri sesi.
 */
export function startSessionSync(onLogout) {
  const channel = sharedChannel();
  if (!channel) return;

  channel.onmessage = (event) => {
    const message = event.data;
    if (message?.type === 'request') {
      const token = getToken();
      if (token) channel.postMessage({ type: 'token', token });
    } else if (message?.type === 'logout') {
      onLogout();
    }
  };
}

export function broadcastLogout() {
  sharedChannel()?.postMessage({ type: 'logout' });
}

/**
 * Dipakai auth.restore() SEBELUM memutuskan "belum login". Mengembalikan
 * token yang diterima (sudah disimpan ke sessionStorage tab ini) atau null.
 */
export function adoptTokenFromOtherTabs(timeoutMs = HANDOFF_TIMEOUT_MS) {
  const existing = getToken();
  if (existing) return Promise.resolve(existing);

  // Kanal sementara khusus penanya: hanya ia yang menerima balasan 'token'.
  const channel = openChannel();
  if (!channel) return Promise.resolve(null);

  return new Promise((resolve) => {
    const finish = (token) => {
      clearTimeout(timer);
      channel.close();
      resolve(token);
    };
    const timer = setTimeout(() => finish(null), timeoutMs);

    channel.onmessage = (event) => {
      const message = event.data;
      if (message?.type === 'token' && typeof message.token === 'string' && message.token !== '') {
        setToken(message.token);
        finish(message.token);
      }
    };
    channel.postMessage({ type: 'request' });
  });
}
