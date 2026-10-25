import { describe, it, expect, beforeEach } from 'vitest';
import { render } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';
import { useBranding } from '../../resources/js/composables/useBranding';
import { useSettingsStore } from '../../resources/js/stores/settings';

// 072 — judul tab dan favicon mengikuti identitas toko tanpa muat ulang, dan kembali ke bawaan saat dilepas.
const Host = { setup() { useBranding(); return () => null; }, template: '<div />' };
const icon = () => document.head.querySelector('link[rel="icon"]');

function mount(initial = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const settings = useSettingsStore();
  settings.$patch({ appName: 'BoothPOS', ...initial });
  const view = render(Host, { global: { plugins: [pinia] } });
  return { settings, ...view };
}

describe('useBranding', () => {
  beforeEach(() => {
    document.title = 'BoothPOS';
    document.head.querySelectorAll('link[rel="icon"]').forEach((el) => el.remove());
  });

  it('menyetel judul "{toko} – {aplikasi}" saat dipasang dan mengikuti perubahan tanpa muat ulang', async () => {
    const { settings } = mount({ storeName: 'Warkop WJM' });
    expect(document.title).toBe('Warkop WJM – BoothPOS');

    settings.storeName = 'Kopi Baru';
    settings.appName = 'Kasir';
    await nextTick();
    expect(document.title).toBe('Kopi Baru – Kasir');

    settings.storeName = 'kasir';
    await nextTick();
    expect(document.title).toBe('Kasir');
  });

  it('favicon memakai logo toko, mengikuti penggantian, dan kembali ke bawaan bila logo hilang', async () => {
    const { settings } = mount({ storeLogoUrl: '/storage/store-logo/a.png' });
    expect(icon().getAttribute('href')).toBe('/storage/store-logo/a.png');

    settings.storeLogoUrl = '/storage/store-logo/b.png';
    await nextTick();
    expect(icon().getAttribute('href')).toBe('/storage/store-logo/b.png');

    settings.storeLogoUrl = null;
    await nextTick();
    expect(icon().getAttribute('href')).toBe('/favicon.ico');
  });

  it('memakai elemen ikon yang sudah ada alih-alih membuat duplikat', () => {
    const existing = document.createElement('link');
    existing.rel = 'icon';
    existing.href = '/favicon.ico';
    document.head.appendChild(existing);

    mount({ storeLogoUrl: '/storage/store-logo/a.png' });

    expect(document.head.querySelectorAll('link[rel="icon"]')).toHaveLength(1);
    expect(existing.getAttribute('href')).toBe('/storage/store-logo/a.png');
  });

  it('saat dilepas (mis. logout) judul dan ikon kembali ke bawaan', () => {
    const { unmount } = mount({ storeName: 'Warkop WJM', storeLogoUrl: '/storage/store-logo/a.png' });
    unmount();

    expect(document.title).toBe('BoothPOS');
    expect(icon().getAttribute('href')).toBe('/favicon.ico');
  });
});
