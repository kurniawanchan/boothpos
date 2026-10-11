import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
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

// 041-back-office-ui-redesign (US2) — struktur halaman Sales: PageHeader, KpiStrip, FilterBar ("N data"),
// keadaan galat. Perilaku daftar/filter/ekspor sudah dijaga SalesView*.test.js; di sini hanya kerangka baru.
const tx = (n, extra = {}) => ({
  key: `order:${n}`, id: n, order_number: `ORD-${n}`, status: 'completed', customer_name: null, created_at: `2026-09-0${n - 400}T09:00:00Z`,
  cashier_id: n === 403 ? 2 : 1, cashier_name: n === 403 ? 'Kasir B' : 'Kasir A', session_id: 1, item_count: 1, unit_count: 1,
  total_amount: '10000.00', cash_amount: '10000.00', noncash_amount: '0.00', payment_methods: ['cash'], artist_names: ['Nekoyama Studio'],
  items_preview: [], items_more: 0, payment_state: 'verified', discount_amount: '0.00', ...extra,
});
const DATA = {
  event: { id: 1, name: 'Event A', start_date: '2026-09-01', end_date: '2026-09-03' },
  totals: { order_count: 3, unit_count: 9, gross_sales: '99.00', net_sales: '99.00', pos_unit_count: 1234, pos_gross_sales: '30000.00', pos_net_sales: '28000.00' },
  sessions: [],
  transactions: [tx(401), tx(402), tx(403)],
};

async function mountSales() {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  render(SalesView, { global: { plugins: [pinia, router] } });
  return user;
}

describe('SalesView — struktur halaman (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(DATA);
  });

  it('memakai PageHeader: satu judul "Penjualan" + deskripsi, tanpa judul ganda', async () => {
    await mountSales();
    await screen.findByText('ORD-401');

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Penjualan');
    expect(screen.getByText('Ringkasan transaksi, daftar struk, dan pencarian')).toBeInTheDocument();
  });

  it('empat angka KPI memakai angka POS dari server (pos_*), diformat ribuan', async () => {
    await mountSales();
    await screen.findByText('ORD-401');

    const cards = screen.getByTestId('kpi-cards');
    expect(within(cards).getByText('Transaksi').nextElementSibling).toHaveTextContent('3');
    expect(within(cards).getByText('Unit terjual').nextElementSibling).toHaveTextContent('1.234');
    expect(within(cards).getByText('Penjualan kotor').nextElementSibling).toHaveTextContent(/Rp\s*30\.000/);
    expect(within(cards).getByText('Penjualan bersih').nextElementSibling).toHaveTextContent(/Rp\s*28\.000/);
  });

  it('penghitung "N data" = jumlah baris yang tampil setelah filter, bukan total server', async () => {
    const user = await mountSales();
    await screen.findByText('ORD-401');
    expect(screen.getByTestId('data-count')).toHaveTextContent('3 data');

    await user.click(screen.getByText('Semua kasir'));
    await user.click(await screen.findByRole('option', { name: 'Kasir B' }));

    expect(screen.getByTestId('data-count')).toHaveTextContent('1 data');
    expect(screen.getByText(/daftar transaksi \(1\)/i)).toBeInTheDocument();
  });

  it('gagal memuat: KPI "tidak tersedia" (bukan 0), tabel menampilkan galat, dan "Coba lagi" memuat ulang', async () => {
    // mockRejectedValue (bukan Once): saat mount, perubahan eventId memicu pemuatan kedua (perilaku lama
    // yang dibiarkan), jadi semua pemuatan awal harus gagal agar keadaan galat stabil.
    salesReport.mockRejectedValue(new Error('network'));
    const user = await mountSales();

    expect(await screen.findByText('Gagal memuat data')).toBeInTheDocument();
    const cards = screen.getByTestId('kpi-cards');
    console.log('CARDS', cards.textContent, salesReport.mock.calls.length);
    expect(within(cards).getAllByText('Tidak tersedia').length).toBeGreaterThanOrEqual(4);
    expect(cards).not.toHaveTextContent(/Rp\s*0/);

    salesReport.mockResolvedValue(DATA);
    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));

    expect(await screen.findByText('ORD-401')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByText('Gagal memuat data')).not.toBeInTheDocument());
  });
});
