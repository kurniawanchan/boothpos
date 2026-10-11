import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/vue';
import { config } from '@vue/test-utils';
import { i18n } from '../resources/js/i18n';
import idMessages from '../resources/js/locales/id.json';
import enMessages from '../resources/js/locales/en.json';

// 002-language-toggle — @testing-library/vue v8 delegates to @vue/test-utils'
// mount() under the hood, so pushing plugins onto VTU's global config here
// applies to every render() call project-wide, without editing each of the
// ~20 test files that call render() with their own `global.plugins` array
// (those per-file arrays — mostly just Pinia — still work identically
// alongside this).
// 043 — pesan dipasang SINKRON di sini (produksi memuatnya per-chunk lewat loadLocale), supaya ratusan tes yang
// membaca teks seketika tidak perlu menunggu import dinamis.
i18n.global.setLocaleMessage('id', idMessages);
i18n.global.setLocaleMessage('en', enMessages);
config.global.plugins.push(i18n);

// The ~20 pre-existing component test files assert Indonesian literal
// text (e.g. `getByRole('button', { name: /produk baru/i })`), written
// before this feature existed. Rather than rewrite every one of them,
// default the test-time locale to 'id' (matching the app's own default
// for guest/pre-login routes) so their assertions keep matching reality.
// A test that specifically exercises the language toggle (e.g.
// LanguageSwitcher.test.js) sets `i18n.global.locale.value` explicitly
// per test, overriding this default.
afterEach(() => {
  i18n.global.locale.value = 'id';
});
i18n.global.locale.value = 'id';

// BUG YANG DITEMUKAN & DIPERBAIKI — tidak ada cleanup() di antar-test sama
// sekali sebelum ini. @testing-library/vue TIDAK auto-unmount komponen
// setelah tiap test seperti sebagian framework lain; tanpa afterEach ini,
// setiap render() dalam SATU file test menumpuk pohon komponen Vue yang
// masih ter-mount di document.body yang sama, membuat `screen` (yang
// terikat ke seluruh document.body) kadang mencocokkan elemen dari test
// SEBELUMNYA yang seharusnya sudah selesai — inilah penyebab flake nyata
// yang ditemukan di RolesView.test.js (createRole tidak pernah dipanggil
// karena `editingRole` milik instance komponen test sebelumnya yang masih
// hidup ikut ter-render ulang). Ini bug tingkat proyek, bukan spesifik
// satu file test — mempengaruhi setiap file di qa-tests/component/.
afterEach(() => {
  cleanup();
});
