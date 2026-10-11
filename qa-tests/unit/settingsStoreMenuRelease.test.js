import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { featureFlags } from '../../resources/js/api/settings';
import { useSettingsStore } from '../../resources/js/stores/settings';

vi.mock('../../resources/js/api/settings', () => ({ featureFlags: vi.fn(), updateSettings: vi.fn() }));

// 043 (T019) — `menu_release_enabled` dari GET /settings/features menentukan apakah banner/angka rilis tampil.
describe('settings store — menuReleaseEnabled (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
  });

  it('is off until the features payload says otherwise', () => {
    expect(useSettingsStore().menuReleaseEnabled).toBe(false);
  });

  it('turns on only for a strict true', async () => {
    const store = useSettingsStore();
    featureFlags.mockResolvedValue({ menu_release_enabled: true });
    await store.load();
    expect(store.menuReleaseEnabled).toBe(true);

    featureFlags.mockResolvedValue({ menu_release_enabled: 'false' });
    await store.load();
    expect(store.menuReleaseEnabled).toBe(false);
  });

  it('stays off when an older server does not send the key', async () => {
    const store = useSettingsStore();
    featureFlags.mockResolvedValue({});
    await store.load();
    expect(store.menuReleaseEnabled).toBe(false);
  });

  it('reads price_rounding_default (none | 500) and falls back to 500 for anything else or a missing key', async () => {
    const store = useSettingsStore();
    expect(store.priceRoundingDefault).toBe('500');
    featureFlags.mockResolvedValue({ price_rounding_default: 'none' });
    await store.load();
    expect(store.priceRoundingDefault).toBe('none');
    featureFlags.mockResolvedValue({ price_rounding_default: 'weird' });
    await store.load();
    expect(store.priceRoundingDefault).toBe('500');
    featureFlags.mockResolvedValue({});
    await store.load();
    expect(store.priceRoundingDefault).toBe('500');
  });
});
