import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getOrder } from '../../resources/js/api/orders';
import { getPaymentProofBlobUrl } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ verifyOrderPayments: vi.fn(), getPaymentProofBlobUrl: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const row = (id, extra = {}) => ({
  key: `order:${id}`, id, order_number: `ORD-00${id}`, status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: '10000.00', artist_names: [], payment_methods: [], has_reference: false, ...extra,
});

/**
 * 056 (US2) — tautan "Payment Proof" di daftar Sales: muncul hanya bila server menyatakan ada bukti yang boleh
 * dilihat (`payment_proof_count`); daftar sendiri tak pernah mengunduh gambar bukti.
 */
describe('SalesView — Payment Proof link (056 US2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({
      event: null, totals: {}, sessions: [],
      transactions: [row(1, { payment_proof_count: 2 }), row(2, { payment_proof_count: 0 }), row(3), row(4, { payment_proof_count: 1, has_reference: true })],
    });
    getPaymentProofBlobUrl.mockImplementation((id) => Promise.resolve(`blob:p-${id}`));
  });

  async function open() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');
    return user;
  }

  const cell = (no) => screen.getByText(no).closest('td');

  it('shows the link only where payment_proof_count > 0 (0 and missing show none)', async () => {
    await open();

    expect(within(cell('ORD-001')).getByTestId('payment-proof-link')).toHaveTextContent('Bukti Pembayaran');
    expect(within(cell('ORD-002')).queryByTestId('payment-proof-link')).not.toBeInTheDocument();
    expect(within(cell('ORD-003')).queryByTestId('payment-proof-link')).not.toBeInTheDocument();
  });

  it('keeps both links, distinguishable, when a sale has a reference and a proof', async () => {
    await open();

    const c = cell('ORD-004');
    expect(within(c).getByTestId('reference-link')).toHaveTextContent('Referensi Penjualan');
    expect(within(c).getByTestId('payment-proof-link')).toHaveTextContent('Bukti Pembayaran');
  });

  it('opens the proofs of THAT sale in a dialog without opening the full detail', async () => {
    getOrder.mockResolvedValue({
      id: 1, order_number: 'ORD-001',
      payments: [{ id: 5, method: 'qr_ewallet', amount: '10000.00', proof_id: 31, has_proof: true, can_view_proof: true }],
    });
    const user = await open();

    await user.click(within(cell('ORD-001')).getByTestId('payment-proof-link'));

    expect(await screen.findByTestId('payment-proof-item')).toBeInTheDocument();
    expect(getOrder).toHaveBeenCalledTimes(1);
    expect(getOrder).toHaveBeenCalledWith(1);
    expect(screen.queryByText(/Produk Terjual|Detail transaksi/i)).not.toBeInTheDocument();
  });

  it('never downloads a proof image while merely listing sales (SC-003)', async () => {
    await open();

    expect(getPaymentProofBlobUrl).not.toHaveBeenCalled();
    expect(getOrder).not.toHaveBeenCalled();
  });
});
