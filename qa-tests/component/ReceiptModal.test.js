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

describe('ReceiptModal event footer (014-sales-receipt-event-footer US2)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders location and a formatted date RANGE when start/end dates differ', async () => {
    renderModal({
      event_location: 'Jakarta Convention Center',
      event_start_date: '2026-09-01',
      event_end_date: '2026-09-03',
    });

    await screen.findByText('ORD-0001');
    expect(screen.getByText(/Jakarta Convention Center/)).toBeInTheDocument();

    const expectedRange = `${formatDate('2026-09-01')} – ${formatDate('2026-09-03')}`;
    expect(screen.getByText(new RegExp(`Tanggal:\\s*${expectedRange.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`))).toBeInTheDocument();
  });

  it('renders ONE date, not a redundant range, when start and end dates are the same', async () => {
    renderModal({
      event_location: 'Jakarta Convention Center',
      event_start_date: '2026-09-01',
      event_end_date: '2026-09-01',
    });

    await screen.findByText('ORD-0001');
    const single = formatDate('2026-09-01');
    const dateEl = screen.getByText(new RegExp(`Tanggal:\\s*${single.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
    expect(dateEl).toBeInTheDocument();
    expect(dateEl.textContent).not.toMatch('–');
    expect(dateEl.textContent).not.toBe(`Tanggal: ${single} – ${single}`);
  });

  it('omits the entire event-info footer block when location and dates are all absent', async () => {
    renderModal({
      event_location: null,
      event_start_date: null,
      event_end_date: null,
    });

    await screen.findByText('ORD-0001');
    expect(screen.queryByText(/Lokasi:/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Tanggal:/)).not.toBeInTheDocument();
  });
});
