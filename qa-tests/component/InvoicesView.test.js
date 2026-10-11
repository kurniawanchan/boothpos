import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import InvoicesView from '../../resources/js/views/InvoicesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listInvoices, getInvoiceSummary, createInvoice, updateInvoice, deleteInvoice, markInvoicePaid, cancelInvoice } from '../../resources/js/api/invoices';
import { listCompanies } from '../../resources/js/api/companies';
import { listLicenses } from '../../resources/js/api/licenses';

vi.mock('../../resources/js/api/invoices', () => ({
  listInvoices: vi.fn(),
  getInvoice: vi.fn(),
  getInvoiceSummary: vi.fn(),
  createInvoice: vi.fn(),
  updateInvoice: vi.fn(),
  deleteInvoice: vi.fn(),
  markInvoicePaid: vi.fn(),
  cancelInvoice: vi.fn(),
}));

vi.mock('../../resources/js/api/companies', () => ({
  listCompanies: vi.fn(),
  getCompany: vi.fn(),
  createCompany: vi.fn(),
  resendActivation: vi.fn(),
  activateCompany: vi.fn(),
}));

vi.mock('../../resources/js/api/licenses', () => ({
  listLicenses: vi.fn(),
  createLicense: vi.fn(),
  updateLicense: vi.fn(),
  deleteLicense: vi.fn(),
}));


// 041-back-office-ui-redesign — filter/tab kini dicerminkan ke address bar (useQueryState), jadi view
// butuh router; router memori dengan satu rute cukup (tidak ada navigasi sungguhan di tes ini).
async function makeRouter(path = '/') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  await router.push(path);
  await router.isReady();
  return router;
}

async function renderInvoices(role = 'Owner', path = '/') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role, name: role, menu_keys: ['dashboard', 'invoices'] };
  const router = await makeRouter(path);
  return { router, ...render(InvoicesView, { global: { plugins: [pinia, router] } }) };
}

const SAMPLE_INVOICE = {
  id: 1,
  invoice_number: 'INV-0001',
  company_id: 10,
  license_id: 20,
  license: { id: 20, name: 'Paket Pro', payment_type: 'subscription' },
  subtotal: '1000000.00',
  discount: '0.00',
  grand_total: '1000000.00',
  due_date: '2026-10-01',
  status: 'unpaid',
  paid_at: null,
  payment_information: null,
  notes: null,
  created_at: '2026-09-01T00:00:00Z',
};

const PAID_INVOICE = { ...SAMPLE_INVOICE, id: 3, invoice_number: 'INV-0003', status: 'paid', paid_at: '2026-09-02T00:00:00Z' };

describe('InvoicesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listInvoices.mockResolvedValue({
      data: [SAMPLE_INVOICE],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
    getInvoiceSummary.mockResolvedValue({
      unpaid: { count: 1, total: '1000000.00' },
      paid: { count: 0, total: '0.00' },
      overall_count: 1,
    });
    listCompanies.mockResolvedValue({
      data: [{ id: 10, name: 'PT Contoh Jaya' }],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    });
    listLicenses.mockResolvedValue({
      data: [{ id: 20, name: 'Paket Pro', payment_type: 'subscription' }],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    });
  });

  it('lists invoices with resolved company name and statistics', async () => {
    await renderInvoices();
    expect(await screen.findByText('INV-0001')).toBeInTheDocument();
    expect(await screen.findByText('PT Contoh Jaya')).toBeInTheDocument();
    // Statistics panel wired to GET /invoices/summary.
    await waitFor(() => expect(getInvoiceSummary).toHaveBeenCalled());
    expect(screen.getByText('1 invoice')).toBeInTheDocument();
  });

  it('opens the detail modal when the invoice number is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderInvoices();

    await user.click(await screen.findByText('INV-0001'));
    expect(await screen.findByText('Detail Invoice')).toBeInTheDocument();
    expect(screen.getByText('Tandai Lunas')).toBeInTheDocument();
  });

  it('creates an invoice via the form and refreshes the list/summary', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createInvoice.mockResolvedValue({ ...SAMPLE_INVOICE, id: 2, invoice_number: 'INV-0002' });
    await renderInvoices();

    await screen.findByText('INV-0001');
    await user.click(screen.getByRole('button', { name: /buat invoice/i }));
    await user.type(await screen.findByLabelText(/subtotal/i), '500000');
    await user.type(screen.getByLabelText(/jatuh tempo/i), '2026-11-01');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createInvoice).toHaveBeenCalled());
  });

  it('marks an invoice paid from the detail modal', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    markInvoicePaid.mockResolvedValue({ ...SAMPLE_INVOICE, status: 'paid' });
    await renderInvoices();

    await user.click(await screen.findByText('INV-0001'));
    await user.click(await screen.findByText('Tandai Lunas'));

    await waitFor(() => expect(markInvoicePaid).toHaveBeenCalledWith(1));
  });

  it('shows Edit/Delete buttons in the detail modal when the invoice is unpaid', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderInvoices();

    await user.click(await screen.findByText('INV-0001'));
    expect(await screen.findByRole('button', { name: /^edit$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^hapus$/i })).toBeInTheDocument();
  });

  it('hides Edit/Delete buttons in the detail modal when the invoice is paid', async () => {
    listInvoices.mockResolvedValue({
      data: [PAID_INVOICE],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderInvoices();

    await user.click(await screen.findByText('INV-0003'));
    await screen.findByText('Detail Invoice');
    expect(screen.queryByRole('button', { name: /^edit$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^hapus$/i })).not.toBeInTheDocument();
  });

  it('opens the form pre-filled when Edit is clicked from the detail modal', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderInvoices();

    await user.click(await screen.findByText('INV-0001'));
    await user.click(await screen.findByRole('button', { name: /^edit$/i }));

    expect(await screen.findByText('Ubah Invoice')).toBeInTheDocument();
    expect(await screen.findByDisplayValue('1000000.00')).toBeInTheDocument();
  });

  it('deletes an invoice after confirming and refreshes the list', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deleteInvoice.mockResolvedValue({});
    await renderInvoices();

    await user.click(await screen.findByText('INV-0001'));
    await user.click(await screen.findByRole('button', { name: /^hapus$/i }));
    await user.click(await screen.findByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteInvoice).toHaveBeenCalledWith(1));
    await waitFor(() => expect(listInvoices).toHaveBeenCalledTimes(2));
  });

  // --- 041-back-office-ui-redesign (struktur halaman baru) ---

  it('memakai PageHeader; Ekspor/Impor/Unduh Template hanya untuk owner/admin (disembunyikan, bukan dinonaktifkan)', async () => {
    await renderInvoices();
    expect(screen.getByRole('heading', { level: 1, name: 'Invoice' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^ekspor$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^impor$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /unduh template/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /buat invoice/i })).toBeInTheDocument();
  });

  it('peran non-owner/admin tidak merender tombol ekspor/impor, tombol buat tetap ada', async () => {
    await renderInvoices('Cashier');
    await screen.findByText('INV-0001');
    expect(screen.queryByRole('button', { name: /^ekspor$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^impor$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /unduh template/i })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /buat invoice/i })).toBeInTheDocument();
  });

  it('empat angka KPI persis dari /invoices/summary (dibatalkan diturunkan dari sisanya)', async () => {
    getInvoiceSummary.mockResolvedValue({
      unpaid: { count: 3, total: '3500000.00' },
      paid: { count: 5, total: '12000000.00' },
      overall_count: 10,
    });
    await renderInvoices();
    await screen.findByText('INV-0001');
    const strip = screen.getByText('Total Invoice').closest('section');
    expect(strip).toHaveTextContent('10');
    expect(strip).toHaveTextContent('Rp 3.500.000');
    expect(strip).toHaveTextContent('3 invoice');
    expect(strip).toHaveTextContent('Rp 12.000.000');
    expect(strip).toHaveTextContent('5 invoice');
    // 10 - 3 - 5 = 2 dibatalkan
    expect(screen.getByText('Dibatalkan', { selector: 'span' }).closest('div')).toHaveTextContent('2');
  });

  it('ringkasan gagal dimuat: angka tampil "tidak tersedia" (bukan 0) dan tabel tetap tampil', async () => {
    getInvoiceSummary.mockRejectedValue(new Error('network'));
    await renderInvoices();
    expect(await screen.findByText('INV-0001')).toBeInTheDocument();
    const strip = screen.getByText('Total Invoice').closest('section');
    expect(strip.querySelectorAll('.sr-only')).toHaveLength(4);
    expect(strip).not.toHaveTextContent('Rp 0');
  });

  it('tab status membawa jumlah dari ringkasan, memfilter lewat parameter status, dan masuk ke URL', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getInvoiceSummary.mockResolvedValue({
      unpaid: { count: 1, total: '1000000.00' },
      paid: { count: 4, total: '4000000.00' },
      overall_count: 6,
    });
    const { router } = await renderInvoices();
    await screen.findByText('INV-0001');

    const tabs = screen.getAllByRole('tab');
    expect(tabs.map((t) => t.textContent.replace(/\s+/g, ' ').trim())).toEqual([
      'Semua 6',
      'Belum Bayar 1',
      'Lunas 4',
      'Dibatalkan 1',
    ]);
    expect(screen.getByRole('tab', { name: /semua/i })).toHaveAttribute('aria-selected', 'true');

    await user.click(screen.getByRole('tab', { name: /lunas/i }));
    await waitFor(() => expect(listInvoices).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'paid', page: 1 })));
    await waitFor(() => expect(router.currentRoute.value.query.status).toBe('paid'));

    await user.click(screen.getByRole('tab', { name: /semua/i }));
    await waitFor(() => expect(listInvoices).toHaveBeenLastCalledWith(expect.not.objectContaining({ status: 'paid' })));
    await waitFor(() => expect(router.currentRoute.value.query.status).toBeUndefined());
  });

  it('tautan ?status=cancelled langsung memuat tab tersebut; nilai tak dikenal jatuh ke semua', async () => {
    await renderInvoices('Owner', '/?status=cancelled');
    await waitFor(() => expect(listInvoices).toHaveBeenCalledWith(expect.objectContaining({ status: 'cancelled' })));
    expect(screen.getByRole('tab', { name: /dibatalkan/i })).toHaveAttribute('aria-selected', 'true');
  });

  it('status tak dikenal pada URL diabaikan (tab Semua, tanpa parameter status)', async () => {
    await renderInvoices('Owner', '/?status=bogus');
    await screen.findByText('INV-0001');
    expect(screen.getByRole('tab', { name: /semua/i })).toHaveAttribute('aria-selected', 'true');
    expect(listInvoices.mock.calls[0][0]).not.toHaveProperty('status');
  });

  it('penghitung "N data" mengikuti total hasil server', async () => {
    listInvoices.mockResolvedValue({
      data: [SAMPLE_INVOICE],
      meta: { current_page: 1, per_page: 25, total: 42, last_page: 2 },
    });
    await renderInvoices();
    await screen.findByText('INV-0001');
    expect(screen.getByTestId('data-count')).toHaveTextContent('42 data');
  });

  it('gagal memuat daftar menampilkan keadaan galat dengan coba lagi', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listInvoices.mockRejectedValueOnce(new Error('network'));
    await renderInvoices();
    expect(await screen.findByText('Gagal memuat data')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('INV-0001')).toBeInTheDocument();
  });
});
