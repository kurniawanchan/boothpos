import { createI18n } from 'vue-i18n';
import id from './locales/id.json';
import en from './locales/en.json';

// fallbackLocale 'id' bukan 'en' — 'id' adalah bahasa sumber tempat semua
// string ini pertama kali ditulis (lihat research.md 002-language-toggle
// Decision 3). Kunci yang belum sempat diterjemahkan ke English akan
// jatuh ke teks Indonesia aslinya, bukan tampil kosong.
export const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'id',
  messages: { id, en },
});
