import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { featureFlags, updateSettings } from '../../resources/js/api/settings';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn(),
  updateSettings: vi.fn(),
}));

/**
 * Nama aplikasi (merek produk, default "BoothPOS") — dibaca dari
 * GET /settings/features, diubah lewat baris settings `app_name`.
 * Server tetap sumber kebenaran untuk default-nya; store ini hanya cermin.
 */
describe('settings store — app name', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
  });

  it('starts as BoothPOS before anything is loaded', () => {
    expect(useSettingsStore().appName).toBe('BoothPOS');
  });

  it('takes the name from the features payload', async () => {
    featureFlags.mockResolvedValue({ app_name: 'Kasir Sakana' });
    const store = useSettingsStore();

    await store.load();

    expect(store.appName).toBe('Kasir Sakana');
  });

  it('falls back to BoothPOS when the payload has no usable name', async () => {
    const store = useSettingsStore();

    featureFlags.mockResolvedValue({});
    await store.load();
    expect(store.appName).toBe('BoothPOS');

    featureFlags.mockResolvedValue({ app_name: '   ' });
    await store.load();
    expect(store.appName).toBe('BoothPOS');
  });

  it('setAppName saves the trimmed name through the bulk settings call and updates the state', async () => {
    updateSettings.mockResolvedValue({ data: [] });
    const store = useSettingsStore();

    await store.setAppName('  Kasir Sakana  ');

    expect(updateSettings).toHaveBeenCalledWith([{ key: 'app_name', value: 'Kasir Sakana', type: 'string', group: 'general' }]);
    expect(store.appName).toBe('Kasir Sakana');
  });

  it('a blank name is saved as null and resets the app to BoothPOS', async () => {
    updateSettings.mockResolvedValue({ data: [] });
    const store = useSettingsStore();
    store.appName = 'Kasir Sakana';

    await store.setAppName('   ');

    expect(updateSettings).toHaveBeenCalledWith([{ key: 'app_name', value: null, type: 'string', group: 'general' }]);
    expect(store.appName).toBe('BoothPOS');
  });

  it('keeps the previous name when saving fails', async () => {
    updateSettings.mockRejectedValue(new Error('boom'));
    const store = useSettingsStore();
    store.appName = 'Kasir Sakana';

    await expect(store.setAppName('Lain')).rejects.toThrow('boom');

    expect(store.appName).toBe('Kasir Sakana');
  });
});
