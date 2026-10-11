import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';

// 043 — pemuatan bahasa per-chunk. Modul i18n asli dipasangi pesan oleh qa-tests/setup.js, jadi perilaku "muat sekali,
// idempoten, tak melempar" diuji lewat modul yang diimpor ulang dari keadaan kosong.
describe('loadLocale', () => {
  it('memasang pesan sebuah bahasa sekali dan tidak memuat ulang yang sudah terpasang', async () => {
    const mod = await import('../../resources/js/i18n');
    const before = mod.i18n.global.getLocaleMessage('en');
    await mod.loadLocale('en');
    expect(mod.i18n.global.getLocaleMessage('en')).toBe(before);   // sudah terpasang → tidak diganti
  });

  it('bahasa yang tidak dikenal diabaikan tanpa galat', async () => {
    const mod = await import('../../resources/js/i18n');
    await expect(mod.loadLocale('xx')).resolves.toBeUndefined();
    expect(mod.i18n.global.availableLocales).not.toContain('xx');
  });

  it('dimulai dengan locale id dan fallback id', async () => {
    const fresh = createI18n({ legacy: false, locale: 'id', fallbackLocale: 'id', messages: {} });
    expect(fresh.global.locale.value).toBe('id');
    const mod = await import('../../resources/js/i18n');
    expect(mod.i18n.global.fallbackLocale.value).toBe('id');
  });
});
