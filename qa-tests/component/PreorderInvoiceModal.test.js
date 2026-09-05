import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createI18n } from 'vue-i18n';
import PreorderInvoiceModal from '../../resources/js/components/preorder/PreorderInvoiceModal.vue';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

/**
 * 013-preorder-list-filters-receipt (US3, T017) — PreorderInvoiceModal.vue
 * now layers a live-status StatusPill (STATUS_VARIANT/STATUS_LABEL_KEY,
 * keyed on invoice.status) on top of its pre-existing document_type-driven
 * heading badge. These tests prove the status pill is driven by
 * `invoice.status`, independently of `document_type` — not a duplicate of
 * the heading badge, and not hardcoded — and that item rows show
 * `artist_name` when present without leaking a stray "null" when absent.
 */

const arrivedInvoice = vi.hoisted(() => ({
  id: 1,
  preorder_number: 'PO-0001',
  document_type: 'invoice',
  status: 'arrived',
  total_amount: '150000.00',
  paid_amount: '50000.00',
  outstanding: '100000.00',
  customer: { name: 'Siti Aminah' },
  items: [
    { id: 1, name_snapshot: 'Keychain Akatsuki', qty: 1, sell_price: '50000.00', line_total: '50000.00', artist_name: 'Some Seller' },
    { id: 2, name_snapshot: 'Poster Naruto', qty: 2, sell_price: '50000.00', line_total: '100000.00', artist_name: null },
  ],
}));

const cancelledInvoice = vi.hoisted(() => ({
  id: 2,
  preorder_number: 'PO-0002',
  document_type: 'cancelled',
  status: 'cancelled',
  total_amount: '75000.00',
  paid_amount: '0.00',
  outstanding: '75000.00',
  customer: { name: 'Budi Santoso' },
  items: [
    { id: 3, name_snapshot: 'Kaos Anime', qty: 1, sell_price: '75000.00', line_total: '75000.00', artist_name: null },
  ],
}));

const getPreorderInvoiceMock = vi.hoisted(() => vi.fn());

vi.mock('../../resources/js/api/preorders', () => ({
  getPreorderInvoice: getPreorderInvoiceMock,
}));

vi.mock('../../resources/js/stores/toast', () => ({
  useToastStore: () => ({ error: vi.fn(), success: vi.fn() }),
}));

function renderModal(props) {
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id, en } });
  return render(PreorderInvoiceModal, { props, global: { plugins: [i18n] } });
}

describe('PreorderInvoiceModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows the live status label (not just the document_type badge) for an "arrived" preorder', async () => {
    getPreorderInvoiceMock.mockResolvedValue(arrivedInvoice);
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    // document_type badge — "Invoice" heading
    expect(screen.getByText('Invoice')).toBeInTheDocument();
    // status pill — driven by invoice.status ('arrived'), distinct text from the heading badge
    expect(screen.getByText(id.preorders.step_arrived)).toBeInTheDocument();
  });

  it('shows the live status label reflecting "cancelled" driven by invoice.status', async () => {
    getPreorderInvoiceMock.mockResolvedValue(cancelledInvoice);
    renderModal({ open: true, preorderId: 2 });

    await screen.findAllByText('PO-0002');
    // Both the document_type heading badge and the status pill render the same
    // Indonesian text for this case ("Dibatalkan") — asserting there are two
    // separate occurrences proves the status pill renders independently,
    // rather than being the same single badge.
    expect(screen.getAllByText(id.events_sessions.status_cancelled).length).toBe(2);
  });

  it('renders artist_name on an item row when present, and no stray "null" when absent', async () => {
    getPreorderInvoiceMock.mockResolvedValue(arrivedInvoice);
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText(/Some Seller/)).toBeInTheDocument();
    expect(screen.queryByText(/null/)).not.toBeInTheDocument();
  });

  // 014-sales-receipt-event-footer (US2, T015) — event name/location/dates
  // footer, mirroring ReceiptModal.vue's eventInfoLine pattern, additionally
  // gated on invoice.event_name being truthy.
  it('renders the event name/location and a date range when the preorder invoice has event info', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      event_name: 'Sakana Fridge Meet & Greet',
      event_location: 'Jakarta',
      event_start_date: '2026-09-10',
      event_end_date: '2026-09-12',
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText(/Jakarta/)).toBeInTheDocument();
    expect(screen.getByText(/–/)).toBeInTheDocument();
    expect(screen.queryByText(/null/)).not.toBeInTheDocument();
  });

  it('omits the entire event-info footer block when the preorder has no event', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      event_name: null,
      event_location: null,
      event_start_date: null,
      event_end_date: null,
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.queryByText(id.events_sessions.location)).not.toBeInTheDocument();
    expect(screen.queryByText(id.events_sessions.col_dates)).not.toBeInTheDocument();
    expect(screen.queryByText(/null/)).not.toBeInTheDocument();
  });
});
