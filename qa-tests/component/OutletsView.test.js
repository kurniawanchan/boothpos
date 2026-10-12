import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import OutletsView from '../../resources/js/views/OutletsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { listOutlets, deleteOutlet } from '../../resources/js/api/outlets';
import { customHeaderCount } from '../../resources/js/composables/usePageHeader';

/**
 * 041-back-office-ui-redesign (US2) — OutletsView memakai PageHeader (tindakan di kanan), FilterBar
 * (cari + "N data" + atur ulang) dan keadaan galat DataTable. Aturan kuota lisensi Pro tetap utuh.
 */
vi.mock('../../resources/js/api/outlets', () => ({
  listOutlets: vi.fn(),
  createOutlet: vi.fn(),
  updateOutlet: vi.fn(),
  deleteOutlet: vi.fn(),
}));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn().mockResolvedValue({ multi_outlet_enabled: true, outlet_count: 1, outlet_limit_reached: false }),
  updateSettings: vi.fn(),
}));

const OUTLET = { id: 1, code: 'ABC', name: 'Outlet Satu', contact_phone: '0812', contact_email: null, is_active: true, product_count: 3 };

function renderOutlets({ menuKeys = ['dashboard', 'outlets'] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  const settings = useSettingsStore();
  // load() di mounted menimpa nilai ini dengan jawaban featureFlags yang di-mock di atas.
  settings.multiOutletEnabled = true;
  return render(OutletsView, { global: { plugins: [pinia] } });
}

describe('OutletsView — struktur halaman (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    customHeaderCount.value = 0;
    listOutlets.mockResolvedValue({ data: [OUTLET], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
  });

  it('PageHeader memuat judul, deskripsi, serta tombol ekspor/impor/tambah di kanan', async () => {
    renderOutlets();
    expect(await screen.findByText('Outlet Satu')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'Outlet' })).toBeInTheDocument();
    expect(screen.getByText('Pemilik merchandise yang dititipkan')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /tambah outlet/i })).toBeEnabled();
    expect(screen.getByRole('button', { name: /ekspor/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /impor/i })).toBeInTheDocument();
    expect(customHeaderCount.value).toBe(1);
  });

  it('menyembunyikan ekspor/impor bagi peran tanpa menu outlet', async () => {
    renderOutlets({ menuKeys: ['dashboard'] });
    await screen.findByText('Outlet Satu');
    expect(screen.queryByRole('button', { name: /ekspor/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /impor/i })).not.toBeInTheDocument();
  });

  it('penghitung "N data" mengikuti total server dan pencarian memanggil listOutlets (debounce) dengan atur ulang', async () => {
    listOutlets.mockResolvedValue({ data: [OUTLET], meta: { current_page: 1, per_page: 25, total: 42, last_page: 2 } });
    renderOutlets();
    await screen.findByText('Outlet Satu');
    expect(screen.getByTestId('data-count')).toHaveTextContent('42 data');
    expect(screen.queryByRole('button', { name: /atur ulang/i })).not.toBeInTheDocument();

    await fireEvent.update(screen.getByRole('searchbox'), 'abc');
    await waitFor(() => expect(listOutlets).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'abc', page: 1 })), { timeout: 2000 });

    await fireEvent.click(await screen.findByRole('button', { name: /atur ulang/i }));
    await waitFor(() => expect(listOutlets).toHaveBeenLastCalledWith(expect.not.objectContaining({ search: 'abc' })));
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });

  it('tombol Tambah tetap nonaktif dengan banner penjelas saat kuota Pro habis (perilaku lama)', async () => {
    const { featureFlags } = await import('../../resources/js/api/settings');
    featureFlags.mockResolvedValueOnce({ multi_outlet_enabled: false, outlet_count: 1, outlet_limit_reached: true });
    renderOutlets();
    await screen.findByText('Outlet Satu');
    await waitFor(() => expect(screen.getByRole('button', { name: /tambah outlet/i })).toBeDisabled());
    expect(screen.getByText(/upgrade|master/i)).toBeInTheDocument();
  });

  it('menonaktifkan outlet lewat konfirmasi dan memanggil deleteOutlet', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deleteOutlet.mockResolvedValue({});
    renderOutlets();
    await screen.findByText('Outlet Satu');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(await screen.findByRole('button', { name: /ya, nonaktifkan/i }));
    await waitFor(() => expect(deleteOutlet).toHaveBeenCalledWith(1));
  });

  it('galat memuat menampilkan keadaan galat dengan tombol coba lagi', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listOutlets.mockRejectedValueOnce(new Error('Server error'));
    renderOutlets();
    expect(await screen.findByRole('alert')).toHaveTextContent('Server error');
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Outlet Satu')).toBeInTheDocument();
  });
});
