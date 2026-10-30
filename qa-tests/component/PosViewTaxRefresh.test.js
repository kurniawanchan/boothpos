import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PosView from '../../resources/js/views/PosView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { featureFlags } from '../../resources/js/api/settings';
import { currentSession } from '../../resources/js/api/sessions';
import { listCategories } from '../../resources/js/api/categories';
import { listOutlets } from '../../resources/js/api/outlets';
import { listProducts } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/settings', () => ({ featureFlags: vi.fn(), updateSettings: vi.fn() }));
vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn(), openSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ createOrder: vi.fn() }));

function renderPos() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos', 'session'] };
  const view = render(PosView, { global: { plugins: [pinia] } });

  return { view, settings: useSettingsStore(pinia) };
}

// 085 / US1 — pratinjau pajak POS tidak boleh memakai tarif basi setelah pemilik mengubahnya di tempat lain.
describe('PosView — tarif pajak pratinjau selalu segar', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listCategories.mockResolvedValue({ data: [] });
    listOutlets.mockResolvedValue({ data: [] });
    listProducts.mockResolvedValue({ data: [] });
  });

  it('memuat ulang pengaturan saat POS dibuka sehingga tarif 100% terbaca, bukan tarif lama', async () => {
    featureFlags.mockResolvedValue({ tax_rate: 100, tax_label: 'PPN', tax_show: true });
    const { settings } = renderPos();

    await waitFor(() => expect(settings.taxRate).toBe(100));
    expect(featureFlags).toHaveBeenCalled();
  });

  it('memuat ulang lagi ketika tab kembali tampil, dan tarif baru dipakai', async () => {
    featureFlags.mockResolvedValue({ tax_rate: 10, tax_label: 'PPN', tax_show: true });
    const { settings } = renderPos();
    await waitFor(() => expect(settings.taxRate).toBe(10));

    featureFlags.mockResolvedValue({ tax_rate: 5, tax_label: 'PPN', tax_show: true });
    document.dispatchEvent(new Event('visibilitychange'));

    await waitFor(() => expect(settings.taxRate).toBe(5));
  });

  it('kegagalan memuat pengaturan tidak merusak layar POS (tarif terakhir dipakai)', async () => {
    featureFlags.mockRejectedValue(new Error('offline'));
    renderPos();
    await waitFor(() => expect(currentSession).toHaveBeenCalled());

    expect(screen.queryByText(/error/i)).not.toBeInTheDocument();
  });
});
