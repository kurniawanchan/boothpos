import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import ReceiptModal from '../../resources/js/components/receipt/ReceiptModal.vue';
import { formatDate } from '../../resources/js/utils/date';

const baseReceipt = {
  order_number: 'ORD-0001',
  store_name: 'Toko Sakana Fridge',
  event_name: 'Sakana Fest 2026',
  created_at: '2026-09-01T10:00:00Z',
  cashier_name: 'Budi',
  items: [
    { qty: 1, name: 'Keychain Akatsuki', price: '50000.00', line_total: '50000.00', artist_name: 'Artist A' },
  ],
  subtotal: '50000.00',
  discount_amount: '0.00',
  total_amount: '50000.00',
  payment_summary: [{ method: 'cash', amount: '50000.00' }],
  change_amount: '0.00',
};

const mockReceipt = vi.hoisted(() => ({ value: null }));

vi.mock('../../resources/js/api/orders', () => ({
  getReceipt: vi.fn(() => Promise.resolve(mockReceipt.value)),
}));

vi.mock('../../resources/js/stores/toast', () => ({
  useToastStore: () => ({ error: vi.fn(), success: vi.fn() }),
}));

function renderModal(receiptOverrides) {
  mockReceipt.value = { ...baseReceipt, ...receiptOverrides };
  return render(ReceiptModal, { props: { open: true, orderId: 1 } });
}

/**
 * 023-event-availability-invoice-redesign (US2, FR-004/FR-005/FR-006) —
 * standout available-on/location block REPLACES the old small
 * "Lokasi:/Tanggal:" footer line (research.md Decision 3).
 */
describe('ReceiptModal standout available-on/location block (023 US2)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders location and the resolved available-on date prominently', async () => {
    renderModal({
      event_location: 'Jakarta Convention Center',
      event_available_on_date: '2026-09-02',
    });

    await screen.findByText('ORD-0001');
    expect(screen.getByText('Jakarta Convention Center')).toBeInTheDocument();
    expect(screen.getByText('Tersedia pada')).toBeInTheDocument();
    expect(screen.getByText(formatDate('2026-09-02'))).toBeInTheDocument();
  });

  it('renders only the location when no available-on date is set', async () => {
    renderModal({
      event_location: 'Jakarta Convention Center',
      event_available_on_date: null,
    });

    await screen.findByText('ORD-0001');
    expect(screen.getByText('Jakarta Convention Center')).toBeInTheDocument();
    expect(screen.queryByText('Tersedia pada')).not.toBeInTheDocument();
  });

  it('omits the entire standout block when location and available-on date are both absent', async () => {
    renderModal({
      event_location: null,
      event_available_on_date: null,
    });

    await screen.findByText('ORD-0001');
    expect(screen.queryByText('Lokasi')).not.toBeInTheDocument();
    expect(screen.queryByText('Tersedia pada')).not.toBeInTheDocument();
  });
});

describe('ReceiptModal powered-by line', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows "Powered by" with the configured app name', async () => {
    renderModal({ app_name: 'Kasir Sakana' });

    expect(await screen.findByText('Powered by Kasir Sakana')).toBeInTheDocument();
  });

  it('falls back to "Powered by BoothPOS" when the payload has no app name', async () => {
    renderModal({});

    expect(await screen.findByText('Powered by BoothPOS')).toBeInTheDocument();
  });
});

// 028-partial-split-payment (US5) — struk penjualan yang dibayar sebagian.
describe('ReceiptModal — partially paid sale (028)', () => {
  it('shows the paid amount, remaining balance and status for a partially paid sale', async () => {
    renderModal({
      total_amount: '100000.00', payment_summary: [{ method: 'cash', amount: '40000.00', reference: 'DP-1' }],
      paid_amount: '40000.00', balance_amount: '60000.00', payment_status: 'partially_paid',
    });

    expect(await screen.findByTestId('receipt-paid')).toHaveTextContent('40.000');
    expect(screen.getByTestId('receipt-balance')).toHaveTextContent('60.000');
    expect(screen.getByTestId('receipt-status')).toHaveTextContent('Dibayar sebagian');
    expect(screen.getByTestId('receipt-payment-ref')).toHaveTextContent('DP-1');
  });

  it('leaves a fully paid receipt unchanged', async () => {
    renderModal({ paid_amount: '50000.00', balance_amount: '0.00', payment_status: 'fully_paid' });

    await screen.findByText('Toko Sakana Fridge');
    expect(screen.queryByTestId('receipt-balance')).not.toBeInTheDocument();
    expect(screen.queryByTestId('receipt-status')).not.toBeInTheDocument();
  });
});
