import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { featureFlags } from '../../resources/js/api/settings';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn(),
  updateSettings: vi.fn(),
}));

/** 048 — tarif dan label pajak keranjang POS dibaca dari GET /settings/features. */
describe('settings store — tax rate and label', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
  });

  it('starts with no tax before anything is loaded', () => {
    const store = useSettingsStore();
    expect(store.taxRate).toBe(0);
    expect(store.taxLabel).toBe('');
  });

  it('takes the rate and label from the features payload', async () => {
    featureFlags.mockResolvedValue({ tax_rate: 10, tax_label: 'PPN' });
    const store = useSettingsStore();

    await store.load();

    expect(store.taxRate).toBe(10);
    expect(store.taxLabel).toBe('PPN');
  });

  it('treats a missing or malformed rate as 0', async () => {
    featureFlags.mockResolvedValue({ tax_rate: null, tax_label: undefined });
    const store = useSettingsStore();

    await store.load();

    expect(store.taxRate).toBe(0);
    expect(store.taxLabel).toBe('');
  });
});
