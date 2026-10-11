import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import CustomersView from '../../resources/js/views/CustomersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listCustomers } from '../../resources/js/api/customers';

vi.mock('../../resources/js/api/customers', () => ({
  listCustomers: vi.fn(),
  createCustomer: vi.fn(),
  updateCustomer: vi.fn(),
  deleteCustomer: vi.fn(),
  customerTransactions: vi.fn(),
  exportCustomers: vi.fn(),
  downloadCustomerImportTemplate: vi.fn(),
  importCustomers: vi.fn(),
}));

// 041-back-office-ui-redesign (US2) — struktur halaman Pelanggan: PageHeader + FilterBar + tabel.
// Perilaku impor sudah dijaga CustomersView.test.js.
const META = (total) => ({ current_page: 1, per_page: 25, total, last_page: 1 });

function mount(role = 'Owner') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role, name: 'U', menu_keys: ['dashboard', 'customers'] };
  return render(CustomersView, { global: { plugins: [pinia] } });
}

describe('CustomersView — struktur halaman (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listCustomers.mockResolvedValue({ data: [{ id: 1, name: 'Budi', phone: null, email: null, social_handle: null }], meta: META(137) });
  });

  it('memakai PageHeader dengan tindakan Ekspor/Impor/Pelanggan baru untuk owner', async () => {
    mount();
    await screen.findByText('Budi');

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Pelanggan');
    expect(screen.getByText('Impor')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Pelanggan baru/ })).toBeInTheDocument();
  });

  it('kasir tidak melihat Ekspor/Impor sama sekali (tersembunyi, bukan dinonaktifkan)', async () => {
    mount('Cashier');
    await screen.findByText('Budi');

    expect(screen.queryByText('Impor')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Pelanggan baru/ })).toBeInTheDocument();
  });

  it('penghitung "N data" memakai total dari server (daftar berpaginasi)', async () => {
    mount();
    await screen.findByText('Budi');

    expect(screen.getByTestId('data-count')).toHaveTextContent('137 data');
  });

  it('mengetik mencari ke server (ditunda) dan "Atur ulang filter" memuat daftar penuh', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    mount();
    await screen.findByText('Budi');
    listCustomers.mockClear();

    await user.type(screen.getByRole('searchbox'), 'bud');
    await waitFor(() => expect(listCustomers).toHaveBeenCalledWith(expect.objectContaining({ search: 'bud', page: 1 })), { timeout: 1500 });

    listCustomers.mockClear();
    await user.click(screen.getByRole('button', { name: 'Atur ulang filter' }));
    await waitFor(() => expect(listCustomers).toHaveBeenCalledWith(expect.objectContaining({ search: undefined })));
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });

  it('gagal memuat: tabel menampilkan galat dan "Coba lagi" memuat ulang', async () => {
    listCustomers.mockRejectedValueOnce(new Error('network'));
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    mount();

    expect(await screen.findByText('Gagal memuat data')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));

    expect(await screen.findByText('Budi')).toBeInTheDocument();
  });
});
