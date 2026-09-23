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
