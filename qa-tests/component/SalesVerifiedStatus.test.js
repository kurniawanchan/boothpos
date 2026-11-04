import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ verifyOrderPayments: vi.fn(), getPaymentProofBlobUrl: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const row = (id, payment_state, payment_methods, extra = {}) => ({
  key: `order:${id}`, id, order_number: `ORD-00${id}`, status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: '10000.00', artist_names: [], payment_methods, payment_state, has_reference: false, ...extra,
});

/**
 * 056 (US4) — kolom Pembayaran menampilkan status "Terverifikasi" secara positif: hanya bila SEMUA pembayaran
 * non-tunai terverifikasi. `payment_state` dari server sudah "terburuk dari semua" (rejected > pending > verified),
 * tetapi tunai lahir `verified` — jadi tanpa metode non-tunai tak boleh ada label (penjualan tunai-saja).
 */
describe('SalesView — Verified status pill (056 US4)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({
      event: null, totals: {}, sessions: [],
      transactions: [
        row(1, 'verified', ['qr_ewallet']),
        row(2, 'verified', ['cash', 'bank_transfer']),
        row(3, 'verified', ['cash']),
        row(4, 'pending', ['qr_ewallet']),
        row(5, 'rejected', ['bank_transfer']),
        row(6, 'none', []),
        row(7, 'verified', ['qr_ewallet'], { status: 'voided' }),
      ],
    });
  });

  async function open() {
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');
  }

  const cell = (no) => screen.getByText(no).closest('tr');

  it('shows "Terverifikasi" for a sale whose non-cash payments are all verified (also next to cash)', async () => {
    await open();

    expect(within(cell('ORD-001')).getByTestId('payment-verified')).toHaveTextContent('Terverifikasi');
    expect(within(cell('ORD-002')).getByTestId('payment-verified')).toBeInTheDocument();
  });

  it('shows nothing for a cash-only sale or a sale without payments', async () => {
    await open();

    expect(within(cell('ORD-003')).queryByTestId('payment-verified')).not.toBeInTheDocument();
    expect(within(cell('ORD-006')).queryByTestId('payment-verified')).not.toBeInTheDocument();
  });

  it('keeps "not verified" and rejected exactly as before, never together with Verified', async () => {
    await open();

    expect(within(cell('ORD-004')).queryByTestId('payment-verified')).not.toBeInTheDocument();
    expect(within(cell('ORD-004')).getByText('Belum diverifikasi')).toBeInTheDocument();
    expect(within(cell('ORD-005')).queryByTestId('payment-verified')).not.toBeInTheDocument();
    expect(within(cell('ORD-005')).getByText('Ditolak')).toBeInTheDocument();
  });
});
