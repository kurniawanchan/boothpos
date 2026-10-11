import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import PreordersView from '../../resources/js/views/PreordersView.vue';
import PreorderSummaryStrip from '../../resources/js/views/preorders/PreorderSummaryStrip.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPreorders, getPreorderSummary } from '../../resources/js/api/preorders';
import { listArtists } from '../../resources/js/api/artists';
import { listEvents } from '../../resources/js/api/events';
import { customHeaderCount } from '../../resources/js/composables/usePageHeader';

/**
 * 041-back-office-ui-redesign (US2) — struktur halaman Pre-order setelah dipecah menjadi subkomponen:
 * PageHeader, strip empat angka dari GET /preorders/summary, FilterBar (penghitung & atur ulang), keadaan
 * galat tabel, dan bilah massal. Perilaku fiturnya sendiri diuji di PreordersView.test.js (tidak diubah).
 */
vi.mock('../../resources/js/api/preorders', () => ({
  listPreorders: vi.fn(),
  getPreorder: vi.fn(),
  createPreorder: vi.fn(),
  updatePreorder: vi.fn(),
  deletePreorder: vi.fn(),
  updatePreorderStatus: vi.fn(),
  updatePreorderDispatchStatus: vi.fn(),
  exportPreorders: vi.fn(),
  downloadPreorderImportTemplate: vi.fn(),
  importPreorders: vi.fn(),
  resendPreorderNotification: vi.fn(),
  getPreorderSummary: vi.fn(),
  bulkPreorderInvoices: vi.fn(),
  bulkEmailPreorderInvoices: vi.fn(),
  duplicatePreorders: vi.fn(),
  splitPreorder: vi.fn(),
  deletePreorderPayment: vi.fn(),
  createPreorderPayment: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/shipments', () => ({ createShipment: vi.fn(), updateShipment: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/customers', () => ({ listCustomers: vi.fn(), createCustomer: vi.fn(), getCustomer: vi.fn() }));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
  updatePaymentConfirmation: vi.fn(),
  getPaymentProofBlobUrl: vi.fn(),
  verifyPayment: vi.fn(),
}));

const ROWS = [
  { id: 10, preorder_number: 'PO-0010', customer_name: 'Siti Aminah', status: 'ordered', fulfillment: 'pickup', total_amount: '100000.00', outstanding: '100000.00', sellers: [] },
  { id: 11, preorder_number: 'PO-0011', customer_name: 'Budi Santoso', status: 'dp_paid', fulfillment: 'pickup', total_amount: '50000.00', outstanding: '20000.00', sellers: [] },
];
const SUMMARY = {
  transaction_count: 1284,
  by_status: [
    { status: 'ordered', count: 2, total_amount: '100000.00' },
    { status: 'dp_paid', count: 3, total_amount: '400000.00' },
  ],
  grand_total: '500000.00',
  total_outstanding: '200000.00',
};

async function renderPreorders(role = 'Owner') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role, name: role, menu_keys: ['dashboard', 'preorders'] };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/preorders', name: 'preorders', component: { template: '<div />' } }] });
  router.push('/preorders');
  await router.isReady();
  return render(PreordersView, { global: { plugins: [pinia, router] } });
}

beforeEach(() => {
  vi.clearAllMocks();
  customHeaderCount.value = 0;
  listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A' }] });
  listEvents.mockResolvedValue({ data: [] });
  listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
  getPreorderSummary.mockResolvedValue(SUMMARY);
});

describe('PreordersView — header, ringkasan, filter (041)', () => {
  it('PageHeader: satu h1 dari nav.preorders + deskripsi, dengan tombol utama Pre-order baru', async () => {
    await renderPreorders();
    await screen.findByText('PO-0010');
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1, name: 'Pre-order' })).toBeInTheDocument();
    expect(screen.getByText('Pesanan, DP, status, dan pengiriman kurir')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /pre-order baru/i })).toBeInTheDocument();
    expect(customHeaderCount.value).toBe(1);
  });

  it('ekspor/impor/unduh template hanya untuk owner/admin (disembunyikan, bukan dinonaktifkan)', async () => {
    const { unmount } = await renderPreorders('Owner');
    await screen.findByText('PO-0010');
    expect(screen.getByRole('button', { name: /ekspor/i })).toBeInTheDocument();
    unmount();
    await renderPreorders('Cashier');
    await screen.findByText('PO-0010');
    expect(screen.queryByRole('button', { name: /ekspor/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /impor/i })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /pre-order baru/i })).toBeInTheDocument();
  });

  it('strip empat angka sama dengan isi ringkasan server; Sudah dibayar = total − sisa', async () => {
    await renderPreorders();
    await screen.findByText('PO-0010');
    const strip = await screen.findByTestId('preorder-summary');
    expect(within(strip).getByText('1.284')).toBeInTheDocument();
    expect(within(strip).getByText('Rp 500.000')).toBeInTheDocument();
    expect(within(strip).getByText('Rp 300.000')).toBeInTheDocument(); // 500.000 - 200.000
    expect(within(strip).getByText('Rp 200.000')).toBeInTheDocument();
    expect(within(strip).getByText('Sudah dibayar')).toBeInTheDocument();
    // rincian per status tetap ada
    expect(within(screen.getByTestId('preorder-status-breakdown')).getByText(/Dipesan · 2 · Rp 100\.000/)).toBeInTheDocument();
  });

  it('angka yang tidak dikirim server tampil "—" (bukan 0), bukan strip palsu', () => {
    render(PreorderSummaryStrip, { props: { summary: { transaction_count: 3 } } });
    expect(screen.getByText('3')).toBeInTheDocument();
    expect(screen.getAllByText('—')).toHaveLength(3);
    expect(screen.queryByText('Rp 0')).not.toBeInTheDocument();
  });

  it('tanpa ringkasan (belum dimuat) strip tidak dirender', () => {
    render(PreorderSummaryStrip, { props: { summary: null } });
    expect(screen.queryByTestId('preorder-summary')).not.toBeInTheDocument();
  });

  it('penghitung "N data" mengikuti total server (bukan jumlah baris di halaman)', async () => {
    listPreorders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 1284, last_page: 52 } });
    await renderPreorders();
    await screen.findByText('PO-0010');
    expect(screen.getByTestId('data-count')).toHaveTextContent('1.284 data');
  });

  it('atur ulang filter hanya muncul saat ada filter, dan mengosongkan pencarian + filter dalam satu pemuatan', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0010');
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();

    await user.click(screen.getByText('Semua status'));
    await user.click(await screen.findByRole('option', { name: 'Dipesan' }));
    await waitFor(() => expect(listPreorders).toHaveBeenLastCalledWith(expect.objectContaining({ status: ['ordered'] })));

    const callsBefore = listPreorders.mock.calls.length;
    await user.click(await screen.findByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listPreorders.mock.calls.length).toBe(callsBefore + 1));
    expect(listPreorders.mock.calls.at(-1)[0].status).toBeUndefined();
    expect(getPreorderSummary.mock.calls.at(-1)[0].status).toBeUndefined();
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();
  });

  it('pencarian nama pelanggan tetap memfilter lewat listPreorders (debounce) dan memuat ulang ringkasan', async () => {
    await renderPreorders();
    await screen.findByText('PO-0010');
    await fireEvent.update(screen.getByRole('searchbox'), 'Siti');
    await waitFor(() => expect(listPreorders).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Siti', page: 1 })), { timeout: 2000 });
    await waitFor(() => expect(getPreorderSummary).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Siti' })));
  });
});

describe('PreordersView — tabel & aksi massal (041)', () => {
  it('galat memuat menampilkan keadaan galat; Coba lagi memuat ulang daftar', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listPreorders.mockRejectedValueOnce(new Error('Jaringan putus'));
    await renderPreorders();
    expect(await screen.findByRole('alert')).toHaveTextContent('Jaringan putus');
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('PO-0010')).toBeInTheDocument();
  });

  it('kotak centang baris tetap berlabel dengan nomor pre-order; "Batalkan pilihan" membersihkan bilah massal', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderPreorders();
    await screen.findByText('PO-0010');
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();

    await user.click(screen.getByRole('checkbox', { name: 'Pilih PO-0010' }));
    const bar = await screen.findByRole('toolbar');
    expect(within(bar).getByText('1 terpilih')).toBeInTheDocument();
    expect(within(bar).getByTestId('bulk-duplicate')).toBeInTheDocument();

    await user.click(within(bar).getByRole('button', { name: /batalkan pilihan/i }));
    await waitFor(() => expect(screen.queryByRole('toolbar')).not.toBeInTheDocument());
  });
});
