import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { featureFlags } from '../../resources/js/api/settings';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn(),
  updateSettings: vi.fn(),
}));

/** 079 — nama dan detail toko di layar diambil dari blok `store` milik outlet terpilih (server), dengan klien lama sebagai cadangan. */
describe('settings store — identitas toko per outlet', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
  });

  it('memakai blok store dari server', async () => {
    featureFlags.mockResolvedValue({
      store_name: 'Kedai Satu',
      store: { name: 'Kedai Satu', address: 'Jl. Melati 5', phone: '0812-111', email: 'satu@example.test', notes: 'Buka 08–22' },
    });
    const store = useSettingsStore();

    await store.load();

    expect(store.storeName).toBe('Kedai Satu');
    expect(store.storeInfo).toEqual({ address: 'Jl. Melati 5', phone: '0812-111', email: 'satu@example.test', notes: 'Buka 08–22' });
  });

  it('server lama tanpa blok store: nama dari store_name, detail kosong', async () => {
    featureFlags.mockResolvedValue({ store_name: 'Toko Lama' });
    const store = useSettingsStore();

    await store.load();

    expect(store.storeName).toBe('Toko Lama');
    expect(store.storeInfo).toEqual({ address: null, phone: null, email: null, notes: null });
  });

  it('pemuatan ulang dengan outlet lain menimpa nama dan detail sepenuhnya (tak ada sisa outlet sebelumnya)', async () => {
    const store = useSettingsStore();
    featureFlags.mockResolvedValueOnce({ store: { name: 'Outlet A', address: 'Jl. A', phone: '1', email: 'a@x.test', notes: 'A' } });
    await store.load();
    featureFlags.mockResolvedValueOnce({ store: { name: 'Outlet B', address: null, phone: null, email: null, notes: null } });
    await store.load();

    expect(store.storeName).toBe('Outlet B');
    expect(store.storeInfo).toEqual({ address: null, phone: null, email: null, notes: null });
  });
});
