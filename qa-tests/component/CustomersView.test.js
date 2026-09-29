import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import CustomersView from '../../resources/js/views/CustomersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listCustomers, importCustomers } from '../../resources/js/api/customers';

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

function renderCustomers() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'customers'] };
  return render(CustomersView, { global: { plugins: [pinia] } });
}

describe('CustomersView — bulk import preview', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listCustomers.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
  });

  // BUG YANG DITEMUKAN & DIPERBAIKI — dry_run=1 dengan baris tidak valid
  // dibalas 200 (bukan 409) oleh CustomerController::import(), tapi
  // doPreviewImport()'s success branch used to hardcode row_errors: []
  // instead of reading result.row_errors, so an invalid file silently
  // showed "0 will be created, 0 will be updated" with no error at all.
  it('shows row errors from a 200 dry-run response instead of silently reporting 0/0', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    importCustomers.mockResolvedValue({
      dry_run: true,
      created_count: 0,
      updated_count: 0,
      row_errors: [{ row: 31, errors: ['Nomor telepon maksimal 30 karakter.'] }],
    });

    renderCustomers();
    await user.click(await screen.findByText('Impor'));

    const file = new File(['x'], 'customers.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const fileInput = document.querySelector('#customer-import-file');
    await user.upload(fileInput, file);

    await user.click(screen.getByText('Pratinjau'));

    await waitFor(() => expect(screen.getByText('Ditemukan kesalahan — tidak ada yang akan disimpan:')).toBeInTheDocument());
    expect(screen.getByText('Tidak ada data yang diubah — perbaiki baris di bawah lalu unggah ulang.')).toBeInTheDocument();
    expect(screen.getByText('31')).toBeInTheDocument();
    expect(screen.getByText('Nomor telepon maksimal 30 karakter.')).toBeInTheDocument();
    expect(screen.queryByText(/akan dibuat/)).not.toBeInTheDocument();
    // Preview button (not yet applied) is what's shown while errors exist —
    // there's no separate disabled "Konfirmasi impor" button in this state.
    expect(screen.queryByText('Konfirmasi impor')).not.toBeInTheDocument();
  });

  it('shows the created/updated summary when the dry run has no row errors', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    importCustomers.mockResolvedValue({ dry_run: true, created_count: 30, updated_count: 0, row_errors: [] });

    renderCustomers();
    await user.click(await screen.findByText('Impor'));

    const file = new File(['x'], 'customers.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    await user.upload(document.querySelector('#customer-import-file'), file);
    await user.click(screen.getByText('Pratinjau'));

    await waitFor(() => expect(screen.getByText('30 pelanggan baru akan dibuat, 0 pelanggan akan diperbarui.')).toBeInTheDocument());
    expect(screen.getByText('Pratinjau — belum ada data yang diubah.')).toBeInTheDocument();
    expect(screen.getByText('Konfirmasi impor')).toBeEnabled();
  });

  it('shows an applied status and an "import another file" action after confirming', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    importCustomers.mockResolvedValueOnce({ dry_run: true, created_count: 30, updated_count: 0, row_errors: [] });
    importCustomers.mockResolvedValueOnce({ dry_run: false, created_count: 30, updated_count: 0, row_errors: [] });

    renderCustomers();
    await user.click(await screen.findByText('Impor'));
    const file = new File(['x'], 'customers.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    await user.upload(document.querySelector('#customer-import-file'), file);
    await user.click(screen.getByText('Pratinjau'));
    await waitFor(() => expect(screen.getByText('Konfirmasi impor')).toBeEnabled());

    await user.click(screen.getByText('Konfirmasi impor'));

    await waitFor(() => expect(screen.getByText('Impor diterapkan — data sudah diperbarui.')).toBeInTheDocument());
    expect(screen.getByText('Impor berkas lain')).toBeInTheDocument();
    expect(screen.queryByText('Konfirmasi impor')).not.toBeInTheDocument();
  });
});
