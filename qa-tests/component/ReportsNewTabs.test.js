import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render as baseRender, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SalesByProductTab from '../../resources/js/components/report/SalesByProductTab.vue';
import SalesByPaymentMethodTab from '../../resources/js/components/report/SalesByPaymentMethodTab.vue';
import {
  salesByProductReport, salesByProductTransactions, salesByPaymentMethodReport, salesByPaymentMethodTransactions,
} from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/reports', () => ({
  salesByProductReport: vi.fn(), salesByProductTransactions: vi.fn(),
  salesByPaymentMethodReport: vi.fn(), salesByPaymentMethodTransactions: vi.fn(),
}));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

// TransactionItemsModal (dibuka dari daftar transaksi) memakai store Pinia.
function render(component, options = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return baseRender(component, { ...options, global: { plugins: [pinia] } });
}

const PRODUCT_REPORT = {
  scope: {}, best_seller_rule: { top: 3, metric: 'units', ties_included: true },
  totals: { units: 17, sales: '255000.00', transaction_count: 9 },
  data: [
    { product_id: 1, product_name: 'Stiker', artist_id: 1, artist_name: 'Nekoyama', units: 10, sales: '100000.00', transaction_count: 6, rank: 1, is_best_seller: true,
      variants: [{ variant_id: 1, variant_name: 'Pink', units: 7, sales: '70000.00' }, { variant_id: 2, variant_name: 'Blue', units: 3, sales: '30000.00' }] },
    { product_id: 2, product_name: 'Pins', artist_id: 1, artist_name: 'Nekoyama', units: 1, sales: '15000.00', transaction_count: 1, rank: 4, is_best_seller: false, variants: [{ variant_id: 3, variant_name: 'Std', units: 1, sales: '15000.00' }] },
  ],
};

const METHOD_REPORT = {
  totals: { transaction_count: 3, amount: '150000.00', verified_amount: '120000.00', unverified_amount: '30000.00' },
  data: [
    { method: 'cash', channel_id: null, label: 'cash', transaction_count: 2, amount: '70000.00', verified_amount: '70000.00', unverified_amount: '0.00' },
    { method: 'qr_ewallet', channel_id: 3, label: 'QRIS BCA', transaction_count: 1, amount: '30000.00', verified_amount: '0.00', unverified_amount: '30000.00' },
  ],
};

// 054 (US10/US11) — dua tab laporan baru.
describe('SalesByProductTab (054 US10)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    salesByProductReport.mockResolvedValue(PRODUCT_REPORT);
    salesByProductTransactions.mockResolvedValue({
      data: [{ order_id: 77, order_number: 'ORD-077', created_at: '2026-11-01T10:00:00Z', cashier_name: 'Kasir A', qty: 2, amount: '20000.00' }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('lists products with units, sales and the best-seller mark only where the server says so', async () => {
    render(SalesByProductTab, { props: { eventId: 1 } });

    expect(await screen.findByText('Stiker')).toBeInTheDocument();
    const stiker = screen.getByText('Stiker').closest('tr');
    expect(within(stiker).getByTestId('best-seller-badge')).toBeInTheDocument();
    expect(within(screen.getByText('Pins').closest('tr')).queryByTestId('best-seller-badge')).not.toBeInTheDocument();
    expect(within(stiker).getByText('10')).toBeInTheDocument();
    expect(salesByProductReport).toHaveBeenCalledWith({ event_id: 1, q: undefined });
  });

  it('shows the rule and the totals', async () => {
    render(SalesByProductTab, { props: { eventId: 1 } });
    await screen.findByText('Stiker');

    expect(screen.getByTestId('best-seller-rule')).toHaveTextContent(/3 produk teratas/);
    expect(screen.getByTestId('product-totals')).toHaveTextContent(/17 unit/);
    expect(screen.getByTestId('product-totals')).toHaveTextContent(/9 transaksi/);
  });

  it('expands the variant breakdown on demand', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    render(SalesByProductTab, { props: { eventId: 1 } });
    await screen.findByText('Stiker');
    expect(screen.queryByText(/Pink · 7/)).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /tampilkan 2 varian/i }));
    expect(screen.getByText(/Pink · 7/)).toBeInTheDocument();
  });

  it('searches by product name (debounced) and sends the term to the server', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    render(SalesByProductTab, { props: { eventId: '' } });
    await screen.findByText('Stiker');

    await user.type(screen.getByLabelText('Cari nama produk atau varian'), 'pin');

    await waitFor(() => expect(salesByProductReport).toHaveBeenLastCalledWith({ event_id: undefined, q: 'pin' }), { timeout: 1500 });
  });

  it('opens the transactions behind a product and each number opens the sale', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    render(SalesByProductTab, { props: { eventId: 1 } });
    await screen.findByText('Stiker');

    await user.click(within(screen.getByText('Stiker').closest('tr')).getByTestId('open-transactions'));

    expect(await screen.findByRole('button', { name: 'ORD-077' })).toBeInTheDocument();
    expect(salesByProductTransactions).toHaveBeenCalledWith(1, expect.objectContaining({ event_id: 1, page: 1 }));
  });

  it('shows an empty state instead of zeros posing as a ranking', async () => {
    salesByProductReport.mockResolvedValue({ ...PRODUCT_REPORT, data: [], totals: { units: 0, sales: '0.00', transaction_count: 0 } });
    render(SalesByProductTab, { props: { eventId: 1 } });

    // Pesan kosong juga tampil sesaat sebelum data tiba (baris belum ada), jadi dicari ulang sampai laporan termuat.
    await waitFor(() => expect(salesByProductReport).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByText('Belum ada penjualan pada cakupan ini.')).toBeInTheDocument());
    expect(screen.queryByTestId('product-totals')).not.toBeInTheDocument();
    expect(screen.getByTestId('best-seller-rule')).toBeInTheDocument(); // laporan benar-benar sudah termuat
  });

  it('shows a retry when loading fails', async () => {
    salesByProductReport.mockRejectedValueOnce(new Error('x'));
    render(SalesByProductTab, { props: { eventId: 1 } });

    expect(await screen.findByRole('button', { name: /coba lagi/i })).toBeInTheDocument();
  });
});

describe('SalesByPaymentMethodTab (054 US11)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    salesByPaymentMethodReport.mockResolvedValue(METHOD_REPORT);
    salesByPaymentMethodTransactions.mockResolvedValue({
      data: [{ order_id: 5, order_number: 'ORD-005', created_at: '2026-11-01T10:00:00Z', cashier_name: 'Kasir A', amount: '30000.00', verification: 'pending' }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('lists each method with its channel, amounts, and verified vs not verified', async () => {
    render(SalesByPaymentMethodTab, { props: { eventId: 1 } });

    expect(await screen.findByText(/QRIS.*QRIS BCA|QRIS BCA/)).toBeInTheDocument();
    const cash = screen.getByText('Tunai').closest('tr');
    expect(within(cash).getByText('2')).toBeInTheDocument();
    expect(salesByPaymentMethodReport).toHaveBeenCalledWith({ event_id: 1 });
  });

  it('shows the grand total and the not-verified amount', async () => {
    render(SalesByPaymentMethodTab, { props: { eventId: 1 } });
    await screen.findByText('Tunai');

    const totals = screen.getByTestId('method-totals');
    expect(totals).toHaveTextContent(/150\.000/);
    expect(totals).toHaveTextContent(/3 transaksi/);
    expect(totals).toHaveTextContent(/30\.000/);
  });

  it('opens the list of transactions for one method with that method and channel', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    render(SalesByPaymentMethodTab, { props: { eventId: 1 } });
    await screen.findByText('Tunai');

    const qrisRow = screen.getAllByTestId('open-transactions')[1];
    await user.click(qrisRow);

    expect(await screen.findByRole('button', { name: 'ORD-005' })).toBeInTheDocument();
    expect(salesByPaymentMethodTransactions).toHaveBeenCalledWith({ method: 'qr_ewallet', channel_id: 3, event_id: 1, page: 1 });
  });

  it('reloads when the event filter changes', async () => {
    const { rerender } = render(SalesByPaymentMethodTab, { props: { eventId: 1 } });
    await screen.findByText('Tunai');

    await rerender({ eventId: '' });

    await waitFor(() => expect(salesByPaymentMethodReport).toHaveBeenLastCalledWith({ event_id: undefined }));
  });
});
