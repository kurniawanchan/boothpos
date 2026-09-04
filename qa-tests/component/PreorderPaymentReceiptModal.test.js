import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createI18n } from 'vue-i18n';
import PreorderPaymentReceiptModal from '../../resources/js/components/preorder/PreorderPaymentReceiptModal.vue';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

const mockPreorder = vi.hoisted(() => ({
  id: 7,
  preorder_number: 'PO-0007',
  status: 'settled',
  total_amount: '500000.00',
  paid_amount: '500000.00',
  outstanding: '0.00',
  customer: { name: 'Budi Santoso' },
  items: [
    { id: 1, name_snapshot: 'Keychain Akatsuki', qty: 2, sell_price: '50000.00', line_total: '100000.00' },
  ],
  payments: [
    { id: 101, method: 'cash', purpose: 'down_payment', amount: '200000.00', paid_at: '2026-09-01T10:00:00Z', verification: 'verified' },
    { id: 102, method: 'bank_transfer', purpose: 'settlement', amount: '300000.00', paid_at: '2026-09-03T15:00:00Z', verification: 'verified' },
  ],
}));

vi.mock('../../resources/js/api/preorders', () => ({
  getPreorder: vi.fn().mockResolvedValue(mockPreorder),
}));

vi.mock('../../resources/js/stores/toast', () => ({
  useToastStore: () => ({ error: vi.fn(), success: vi.fn() }),
}));

function renderModal(props) {
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id, en } });
  return render(PreorderPaymentReceiptModal, { props, global: { plugins: [i18n] } });
}

describe('PreorderPaymentReceiptModal', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders POS-receipt-like structure: line items and totals', async () => {
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    expect(await screen.findByText('PO-0007')).toBeInTheDocument();
    expect(screen.getByText('Budi Santoso')).toBeInTheDocument();
    expect(screen.getByText('Keychain Akatsuki')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 100.000').length).toBeGreaterThan(0);
  });

  it('shows the "Pre-order" marking and current status prominently', async () => {
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findByText('PO-0007');
    expect(screen.getByText('Pre-order')).toBeInTheDocument();
    expect(screen.getByText('Lunas')).toBeInTheDocument();
  });

  it('identifies the specific settlement payment event when given its id, not the lifetime total', async () => {
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findByText('PO-0007');
    expect(screen.getAllByText(/Pelunasan — /).length).toBeGreaterThan(0);
    expect(screen.getAllByText('Rp 300.000').length).toBeGreaterThan(0);
    expect(screen.queryByText('Rp 200.000')).not.toBeInTheDocument();
  });

  it('identifies the specific down-payment event when given its id', async () => {
    renderModal({ open: true, preorderId: 7, paymentId: 101 });

    await screen.findByText('PO-0007');
    expect(screen.getAllByText(/Uang muka \(DP\) — /).length).toBeGreaterThan(0);
    expect(screen.getAllByText('Rp 200.000').length).toBeGreaterThan(0);
    expect(screen.queryByText('Rp 300.000')).not.toBeInTheDocument();
  });
});
