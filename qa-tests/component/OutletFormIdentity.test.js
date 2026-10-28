import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import OutletsView from '../../resources/js/views/OutletsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { listOutlets, createOutlet, updateOutlet } from '../../resources/js/api/outlets';

/**
 * 079 — form outlet: kode 3–5 huruf/angka yang bisa disunting (huruf besar sejak diketik), alamat dikirim dan
 * tampil di daftar, galat 422 per kolom dari server tampil di bawah kolomnya.
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

const OUTLET = { id: 1, code: 'ABC', name: 'Outlet Satu', address: 'Jl. Melati No. 5', contact_phone: '0812', contact_email: null, notes: null, is_active: true, product_count: 3 };

function renderOutlets() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'outlets'] };
  useSettingsStore().multiOutletEnabled = true;
  return render(OutletsView, { global: { plugins: [pinia] } });
}

describe('OutletsView — kode, alamat (079)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: [OUTLET], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
  });

  it('menampilkan alamat di bawah nama outlet pada daftar', async () => {
    renderOutlets();
    expect(await screen.findByTestId('outlet-address')).toHaveTextContent('Jl. Melati No. 5');
  });

  it('kode diketik huruf besar, simbol dibuang, maksimal 5 karakter; alamat ikut terkirim saat membuat', async () => {
    createOutlet.mockResolvedValue({ id: 2 });
    renderOutlets();
    await screen.findByText('Outlet Satu');
    await fireEvent.click(screen.getByRole('button', { name: /tambah outlet/i }));

    const code = screen.getByLabelText(/kode \(3–5 huruf atau angka\)/i);
    await fireEvent.update(code, 'kd-0019');
    expect(code).toHaveValue('KD001');

    await fireEvent.update(screen.getByLabelText(/nama outlet/i), 'Kedai Satu');
    await fireEvent.update(screen.getByLabelText(/^alamat/i), 'Jl. Baru 1');
    await fireEvent.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createOutlet).toHaveBeenCalledWith(expect.objectContaining({ code: 'KD001', name: 'Kedai Satu', address: 'Jl. Baru 1' })));
  });

  it('kode tidak lagi terkunci saat mengubah dan kode baru dikirim ke server', async () => {
    updateOutlet.mockResolvedValue({});
    renderOutlets();
    await screen.findByText('Outlet Satu');
    await fireEvent.click(screen.getByRole('button', { name: /^edit$/i }));

    const code = screen.getByLabelText(/kode \(3–5 huruf atau angka\)/i);
    expect(code).toBeEnabled();
    expect(code).toHaveValue('ABC');
    expect(screen.queryByText(/permanen/i)).not.toBeInTheDocument();

    await fireEvent.update(code, 'wjm2x');
    await fireEvent.click(screen.getByRole('button', { name: /^simpan$/i }));
    await waitFor(() => expect(updateOutlet).toHaveBeenCalledWith(1, expect.objectContaining({ code: 'WJM2X', address: 'Jl. Melati No. 5' })));
  });

  it('galat 422 pada kode dari server tampil di bawah kolom kode', async () => {
    const err = Object.assign(new Error('x'), { isValidation: true, errors: { code: ['Kode outlet ini sudah dipakai outlet lain.'] } });
    updateOutlet.mockRejectedValue(err);
    renderOutlets();
    await screen.findByText('Outlet Satu');
    await fireEvent.click(screen.getByRole('button', { name: /^edit$/i }));
    await fireEvent.click(screen.getByRole('button', { name: /^simpan$/i }));

    expect(await screen.findByText('Kode outlet ini sudah dipakai outlet lain.')).toBeInTheDocument();
  });
});
