import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { i18n } from '../../resources/js/i18n';
import { useAuthStore } from '../../resources/js/stores/auth';
import { salesReport } from '../../resources/js/api/reports';
import { getFloor } from '../../resources/js/api/floor';

vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/tabs', () => ({ getTab: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
  updatePaymentConfirmation: vi.fn(),
  getPaymentProofBlobUrl: vi.fn(),
  verifyPayment: vi.fn(),
  verifyOrderPayments: vi.fn(),
}));

// 044-sales-table-view (US1) — saklar "Daftar order | Meja" di halaman Sales. Yang dijaga: saklar hanya ada
// bagi pengguna dengan akses lantai, URL menyimpan pilihan, daftar order TIDAK berubah/dimuat ulang saat bolak-balik,
// dan tindakan header khusus-daftar tidak tampil di tampilan Meja.
const tx = (n) => ({
  key: `order:${n}`, id: n, order_number: `ORD-${n}`, status: 'completed', customer_name: null, created_at: '2026-09-01T09:00:00Z',
  cashier_id: 1, cashier_name: 'Kasir A', session_id: 1, item_count: 1, unit_count: 1,
  total_amount: '10000.00', cash_amount: '10000.00', noncash_amount: '0.00', payment_methods: ['cash'], outlet_names: [],
  items_preview: [], items_more: 0, payment_state: 'verified', discount_amount: '0.00',
});
const DATA = {
  event: { id: 1, name: 'Event A', start_date: '2026-09-01', end_date: '2026-09-03' },
  totals: { order_count: 2, unit_count: 2, gross_sales: '20000.00', net_sales: '20000.00' },
  sessions: [],
  transactions: [tx(401), tx(402)],
};

async function mountSales({ menus = ['sales', 'floor'], url = '/' } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menus };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  router.push(/[?&](from|to)=/.test(url) ? url : `${url}${url.includes('?') ? '&' : '?'}from=2000-01-01&to=2099-12-31`);
  await router.isReady();
  render(SalesView, { global: { plugins: [pinia, router] } });
  return { router, user: userEvent.setup() };
}

describe('SalesView — saklar Daftar order | Meja (044)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    salesReport.mockResolvedValue(DATA);
    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v0' });
  });

  it('menampilkan dua segmen berlabel Indonesia, bawaannya Daftar order', async () => {
    await mountSales();
    await screen.findByText('ORD-401');

    const group = screen.getByRole('radiogroup', { name: 'Tampilan halaman Sales' });
    expect(group).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Daftar order' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('radio', { name: 'Meja' })).toHaveAttribute('aria-checked', 'false');
    expect(screen.getByTestId('kpi-cards')).toBeInTheDocument();
  });

  it('berlabel Inggris saat bahasa diganti', async () => {
    i18n.global.locale.value = 'en';
    await mountSales();
    await screen.findByText('ORD-401');

    expect(screen.getByRole('radiogroup', { name: 'Sales page view' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Order list' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Tables' })).toBeInTheDocument();
  });

  it('?view=tables langsung membuka tampilan Meja dan menandai segmennya', async () => {
    await mountSales({ url: '/?view=tables' });

    expect(await screen.findByTestId('floor-board')).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Meja' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.queryByTestId('kpi-cards')).not.toBeInTheDocument();
  });

  it('nilai view yang tak dikenal jatuh ke daftar', async () => {
    await mountSales({ url: '/?view=banana' });
    await screen.findByText('ORD-401');

    expect(screen.getByRole('radio', { name: 'Daftar order' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.queryByTestId('floor-board')).not.toBeInTheDocument();
  });

  it('tanpa akses lantai: tidak ada saklar sama sekali, dan ?view=tables jatuh ke daftar', async () => {
    await mountSales({ menus: ['sales'], url: '/?view=tables' });
    await screen.findByText('ORD-401');

    expect(screen.queryByRole('radiogroup')).not.toBeInTheDocument();
    expect(screen.queryByRole('radio', { name: 'Meja' })).not.toBeInTheDocument();
    expect(screen.queryByTestId('floor-board')).not.toBeInTheDocument();
    expect(getFloor).not.toHaveBeenCalled();
  });

  it('bolak-balik mempertahankan filter daftar dan tidak memuat ulang data daftar', async () => {
    const { user } = await mountSales();
    await screen.findByText('ORD-401');
    const calls = salesReport.mock.calls.length;

    await user.type(screen.getByLabelText('Cari transaksi'), '402');
    await waitFor(() => expect(screen.queryByText('ORD-401')).not.toBeInTheDocument());

    await user.click(screen.getByRole('radio', { name: 'Meja' }));
    expect(await screen.findByTestId('floor-board')).toBeInTheDocument();
    expect(screen.queryByLabelText('Cari transaksi')).not.toBeInTheDocument();

    await user.click(screen.getByRole('radio', { name: 'Daftar order' }));
    const search = await screen.findByLabelText('Cari transaksi');
    expect(search).toHaveValue('402');
    expect(screen.getByText('ORD-402')).toBeInTheDocument();
    expect(screen.queryByText('ORD-401')).not.toBeInTheDocument();
    expect(salesReport.mock.calls.length).toBe(calls);
  });

  it('tindakan header khusus-daftar (ekspor, pilih event) hilang di tampilan Meja dan kembali di daftar', async () => {
    const { user } = await mountSales();
    await screen.findByText('ORD-401');
    expect(screen.getByRole('button', { name: /Ekspor tampilan ini/ })).toBeInTheDocument();

    await user.click(screen.getByRole('radio', { name: 'Meja' }));
    await screen.findByTestId('floor-board');
    expect(screen.queryByRole('button', { name: /Ekspor/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Event A')).not.toBeInTheDocument();
    // Judul dan deskripsi halaman tidak berubah.
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Penjualan');

    await user.click(screen.getByRole('radio', { name: 'Daftar order' }));
    expect(await screen.findByRole('button', { name: /Ekspor tampilan ini/ })).toBeInTheDocument();
  });

  it('memilih segmen menulis ?view=tables ke URL dan memilih daftar menghapusnya', async () => {
    const { user, router } = await mountSales();
    await screen.findByText('ORD-401');

    await user.click(screen.getByRole('radio', { name: 'Meja' }));
    await waitFor(() => expect(router.currentRoute.value.query.view).toBe('tables'));

    await user.click(screen.getByRole('radio', { name: 'Daftar order' }));
    await waitFor(() => expect(router.currentRoute.value.query.view).toBeUndefined());
  });
});
