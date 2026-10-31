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

  // Catatan bertanggal 2026-10-31 (perintah pemilik, membalik 083): pajak ditampilkan mati = pajak tidak dihitung.
  it('effectiveTaxRate is the rate when tax is shown and 0 when it is hidden', async () => {
    featureFlags.mockResolvedValue({ tax_rate: 100, tax_label: 'PPN', tax_show: false });
    const store = useSettingsStore();
    await store.load();
    expect(store.taxRate).toBe(100);
    expect(store.effectiveTaxRate).toBe(0);

    featureFlags.mockResolvedValue({ tax_rate: 10, tax_label: 'PPN', tax_show: true });
    await store.load();
    expect(store.effectiveTaxRate).toBe(10);
  });
});

