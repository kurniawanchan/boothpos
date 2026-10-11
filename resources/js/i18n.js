import { createI18n } from 'vue-i18n';

// fallbackLocale 'id' bukan 'en' — 'id' adalah bahasa sumber tempat semua
// string ini pertama kali ditulis (lihat research.md 002-language-toggle
// Decision 3). Kunci yang belum sempat diterjemahkan ke English akan
// jatuh ke teks Indonesia aslinya, bukan tampil kosong.
//
// 043 (keputusan pemilik): berkas terjemahan TIDAK lagi ikut bundel utama (keduanya ~230 kB mentah dan
// terus bertambah). Tiap bahasa menjadi chunk sendiri yang dimuat sesuai kebutuhan: 'id' saat boot (bahasa
// layar login, tamu, dan fallback), 'en' hanya ketika pengguna memakainya. Pengguna 'id' tidak pernah
// mengunduh 'en'.
export const i18n = createI18n({
  legacy: false,
  locale: 'id',
  fallbackLocale: 'id',
  messages: {},
});

const loaders = {
  id: () => import('./locales/id.json'),
  en: () => import('./locales/en.json'),
};

const pending = new Map();

/**
 * Memuat pesan sebuah bahasa sekali saja (idempoten, aman dipanggil paralel). Bahasa yang sudah terpasang —
 * termasuk yang dipasang langsung oleh qa-tests/setup.js — tidak dimuat ulang. Kegagalan memuat TIDAK
 * dilempar: aplikasi tetap hidup dengan fallback 'id' daripada macet di layar kosong.
 */
export function loadLocale(lang) {
  if (!loaders[lang] || i18n.global.availableLocales.includes(lang)) {
    return Promise.resolve();
  }
  if (!pending.has(lang)) {
    pending.set(
      lang,
      loaders[lang]()
        .then((m) => i18n.global.setLocaleMessage(lang, m.default ?? m))
        .catch(() => {})
        .finally(() => pending.delete(lang)),
    );
  }
  return pending.get(lang);
}
