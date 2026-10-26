import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, nextTick } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../resources/js/utils/logoFavicon', () => ({ logoToFavicon: vi.fn() }));

import { logoToFavicon } from '../../resources/js/utils/logoFavicon';
import { useBranding, buildTitle, DEFAULT_FAVICON } from '../../resources/js/composables/useBranding';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';

// 076 (US4) — judul tab & ikon tab. Komponen kecil memasang composable
// persis seperti App.vue; yang diamati adalah document.title dan <link rel=icon>.
const Host = defineComponent({
  setup() {
    useBranding();
    return () => null;
  },
});

const icon = () => document.querySelector('link[rel~="icon"]');

function setup({ authed = true } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  const settings = useSettingsStore();
  if (authed) auth.user = { id: 1, username: 'owner', role: 'owner' };
  return { pinia, auth, settings };
}

describe('buildTitle', () => {
  it('puts the store name first, then the application name, with a middle dot', () => {
    expect(buildTitle('Demo Store', 'BoothPOS')).toBe('Demo Store · BoothPOS');
  });

  it('is just the application name when there is no store name', () => {
    expect(buildTitle('', 'BoothPOS')).toBe('BoothPOS');
    expect(buildTitle('   ', 'BoothPOS')).toBe('BoothPOS');
    expect(buildTitle(null, 'BoothPOS')).toBe('BoothPOS');
  });
});

describe('useBranding', () => {
  beforeEach(() => {
    document.title = '';
    document.head.querySelectorAll('link[rel~="icon"]').forEach((n) => n.remove());
    vi.clearAllMocks();
    logoToFavicon.mockResolvedValue('data:image/png;base64,LOGO');
  });

  afterEach(() => {
    document.head.querySelectorAll('link[rel~="icon"]').forEach((n) => n.remove());
  });

  it('signed out (login screen): default title and default icon, whatever the store settings say', async () => {
    const { pinia, settings } = setup({ authed: false });
    settings.storeName = 'Toko Rahasia';
    settings.storeLogoUrl = '/storage/logo.png';
    settings.appName = 'Nama Kustom';

    mount(Host, { global: { plugins: [pinia] } });
    await flushPromises();

    expect(document.title).toBe('BoothPOS');
    expect(icon().getAttribute('href')).toBe(DEFAULT_FAVICON);
    expect(logoToFavicon).not.toHaveBeenCalled();
  });

  it('signed in: "<store name> · <app name>" and the store logo as the icon', async () => {
    const { pinia, settings } = setup();
    settings.storeName = 'Demo Store';
    settings.appName = 'BoothPOS';
    settings.storeLogoUrl = '/storage/store-logo/abc.png';

    mount(Host, { global: { plugins: [pinia] } });
    await flushPromises();

    expect(document.title).toBe('Demo Store · BoothPOS');
    expect(logoToFavicon).toHaveBeenCalledWith('/storage/store-logo/abc.png');
    expect(icon().getAttribute('href')).toBe('data:image/png;base64,LOGO');
    expect(icon().getAttribute('type')).toBe('image/png');
  });

  it('no store name → application name only; no logo → default icon', async () => {
    const { pinia, settings } = setup();
    settings.storeName = '';
    settings.appName = 'Kasir Sakana';

    mount(Host, { global: { plugins: [pinia] } });
    await flushPromises();

    expect(document.title).toBe('Kasir Sakana');
    expect(icon().getAttribute('href')).toBe(DEFAULT_FAVICON);
  });

  it('follows changes without a reload: new store name, new logo, cleared logo', async () => {
    const { pinia, settings } = setup();
    settings.storeName = 'Toko Lama';
    settings.storeLogoUrl = '/storage/a.png';
    mount(Host, { global: { plugins: [pinia] } });
    await flushPromises();

    settings.setStoreName('Toko Baru');
    logoToFavicon.mockResolvedValueOnce('data:image/png;base64,NEW');
    settings.setStoreLogoUrl('/storage/b.png');
    await flushPromises();

    expect(document.title).toBe('Toko Baru · BoothPOS');
    expect(icon().getAttribute('href')).toBe('data:image/png;base64,NEW');

    settings.setStoreLogoUrl(null);
    await flushPromises();
    expect(icon().getAttribute('href')).toBe(DEFAULT_FAVICON);
  });

  it('a logo that fails to load falls back to the default icon (never a broken one)', async () => {
    const { pinia, settings } = setup();
    logoToFavicon.mockResolvedValue(null);
    settings.storeLogoUrl = '/storage/missing.png';

    mount(Host, { global: { plugins: [pinia] } });
    await flushPromises();

    expect(icon().getAttribute('href')).toBe(DEFAULT_FAVICON);
  });

  it('a slow, superseded logo load never overwrites the newer icon', async () => {
    const { pinia, settings } = setup();
    let releaseSlow;
    logoToFavicon.mockImplementationOnce(() => new Promise((resolve) => (releaseSlow = () => resolve('data:image/png;base64,SLOW'))));
    settings.storeLogoUrl = '/storage/slow.png';
    mount(Host, { global: { plugins: [pinia] } });
    await nextTick();

    logoToFavicon.mockResolvedValueOnce('data:image/png;base64,FAST');
    settings.setStoreLogoUrl('/storage/fast.png');
    await flushPromises();
    releaseSlow();
    await flushPromises();

    expect(icon().getAttribute('href')).toBe('data:image/png;base64,FAST');
  });

  it('logging out returns to the default title and icon', async () => {
    const { pinia, auth, settings } = setup();
    settings.storeName = 'Demo Store';
    settings.storeLogoUrl = '/storage/a.png';
    mount(Host, { global: { plugins: [pinia] } });
    await flushPromises();
    expect(document.title).toBe('Demo Store · BoothPOS');

    auth.user = null;
    await flushPromises();

    expect(document.title).toBe('BoothPOS');
    expect(icon().getAttribute('href')).toBe(DEFAULT_FAVICON);
  });
});
