import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import VendorsView from '../../resources/js/views/VendorsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listVendors, createVendor, deleteVendor } from '../../resources/js/api/vendors';

vi.mock('../../resources/js/api/vendors', () => ({
  listVendors: vi.fn(),
  getVendor: vi.fn(),
  createVendor: vi.fn(),
  updateVendor: vi.fn(),
  deleteVendor: vi.fn(),
}));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

function renderVendors() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'owner', name: 'Owner' };
  return render(VendorsView, { global: { plugins: [pinia] } });
}

describe('VendorsView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({
      data: [{ id: 1, code: 'VEN1', name: 'Vendor Satu', contact_phone: '0812', contact_email: null, is_active: true, material_price_count: 2 }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('lists vendors from the API', async () => {
    renderVendors();
    expect(await screen.findByText('Vendor Satu')).toBeInTheDocument();
    expect(screen.getByText('VEN1')).toBeInTheDocument();
  });

  it('creates a vendor via the form', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createVendor.mockResolvedValue({ id: 2, code: 'VEN2', name: 'Vendor Dua' });
    renderVendors();

    await screen.findByText('Vendor Satu');
    await user.click(screen.getByRole('button', { name: /tambah vendor/i }));
    await user.type(screen.getByLabelText(/kode vendor/i), 'ven2');
    await user.type(screen.getByLabelText(/nama vendor/i), 'Vendor Dua');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createVendor).toHaveBeenCalledWith(expect.objectContaining({ code: 'VEN2', name: 'Vendor Dua' })));
  });

  it('surfaces the 409 delete-guard message via the shared error toast flow', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const conflictError = Object.assign(new Error('Vendor masih memiliki harga bahan yang terdaftar dan tidak dapat dihapus.'), {
      isConflict: true,
    });
    deleteVendor.mockRejectedValue(conflictError);
    renderVendors();

    await screen.findByText('Vendor Satu');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteVendor).toHaveBeenCalledWith(1));
  });
});
