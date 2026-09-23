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

  // 023-event-availability-invoice-redesign (US2, FR-004/FR-005/FR-006) —
  // standout available-on/location block REPLACES the old small footer
  // "Location:/Dates:" line (research.md Decision 3).
  it('renders the standout available-on/location block when the preorder invoice has event info', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      event_name: 'Sakana Fridge Meet & Greet',
      event_location: 'Jakarta',
      event_available_on_date: '2026-09-12',
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText('Jakarta')).toBeInTheDocument();
    expect(screen.getByText(id.events_sessions.available_on_label)).toBeInTheDocument();
    expect(screen.queryByText(/null/)).not.toBeInTheDocument();
  });

  it('omits the standout available-on/location block entirely when the preorder has neither', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      event_name: null,
      event_location: null,
      event_available_on_date: null,
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.queryByText(id.events_sessions.location)).not.toBeInTheDocument();
    expect(screen.queryByText(id.events_sessions.available_on_label)).not.toBeInTheDocument();
    expect(screen.queryByText(/null/)).not.toBeInTheDocument();
  });

  // 022-preorder-invoice-crud-overhaul (US3, FR-008/FR-009/FR-010) — store
  // identity, unmasked payment channels, and footer text render when
  // present in the invoice payload, and are omitted entirely when absent.
  it('renders store identity, unmasked payment channels, and footer text when present', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      store_identity: { name: 'Sakana Fridge', logo_url: null, contact_person: 'Budi', contact_phone: '0812xxxx', contact_email: null, address: 'Jl. Contoh No. 1' },
      payment_channels: [{ id: 1, type: 'bank_transfer', provider: 'BCA', account_name: 'Sakana Fridge', account_number: '1234567890', qr_image_url: null }],
      footer_text: 'Terima kasih sudah berbelanja!',
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText('Sakana Fridge')).toBeInTheDocument();
    expect(screen.getByText('Jl. Contoh No. 1')).toBeInTheDocument();
    expect(screen.getByText('1234567890')).toBeInTheDocument();
    expect(screen.getByText('Terima kasih sudah berbelanja!')).toBeInTheDocument();
  });

  it('omits store identity/payment terms/footer blocks entirely when absent from the payload', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      store_identity: null,
      payment_channels: [],
      footer_text: null,
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.queryByText(id.preorders.payment_terms_title)).not.toBeInTheDocument();
  });

  // 023-event-availability-invoice-redesign (US3, FR-007/FR-008/FR-009/
  // FR-010) — table layout, shipping cost line, bigger QR.
  it('renders items as a table and shows a shipping-cost line when present', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      shipping_cost: '15000.00',
      store_identity: null,
      payment_channels: [],
      footer_text: null,
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(document.querySelector('table')).toBeTruthy();
    expect(screen.getByText('Ongkos kirim')).toBeInTheDocument();
    expect(screen.getByText('Rp 15.000')).toBeInTheDocument();
  });

  it('omits the shipping-cost line when shipping_cost is zero', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      shipping_cost: '0.00',
      store_identity: null,
      payment_channels: [],
      footer_text: null,
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.queryByText('Ongkos kirim')).not.toBeInTheDocument();
  });

  it('renders the payment channel QR at the enlarged size', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      store_identity: null,
      footer_text: null,
      payment_channels: [{ id: 1, type: 'qr_ewallet', provider: 'Gopay', account_name: 'Toko', account_number: null, qr_image_url: '/qr.png' }],
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    const img = screen.getByAltText('Gopay');
    expect(img.className).toContain('h-32');
    expect(img.className).toContain('w-32');
  });

  // 024-invoice-layout-shipping-slip (US1, FR-001/FR-002/FR-003) —
  // two-column header: event info (centered) on the left, order
  // identity + "To:" recipient block on the right.
  it('renders the two-column header with event info on the left and the "To:" block on the right', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      event_name: 'Sakana Fridge Meet & Greet',
      event_location: 'Jakarta',
      event_available_on_date: '2026-09-12',
      customer: { name: 'Siti Aminah', email: 'siti@example.com', phone: '0812xxxx', social_handle: '@siti', address: 'Jl. Melati No. 2' },
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText('Sakana Fridge Meet & Greet')).toBeInTheDocument();
    expect(screen.getByText(id.preorders.to_label)).toBeInTheDocument();
    expect(screen.getByText('siti@example.com')).toBeInTheDocument();
    expect(screen.getByText('0812xxxx')).toBeInTheDocument();
    expect(screen.getByText('@siti')).toBeInTheDocument();
    expect(screen.getByText('Jl. Melati No. 2')).toBeInTheDocument();
  });

  it('omits individual "To:" fields that are absent from the customer payload', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      customer: { name: 'Siti Aminah', email: null, phone: null, social_handle: null, address: null },
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.queryByText(/null/)).not.toBeInTheDocument();
  });

  // 024-invoice-layout-shipping-slip (US2, FR-004) — creation date visible.
  it('renders the preorder creation date', async () => {
    getPreorderInvoiceMock.mockResolvedValue({ ...arrivedInvoice, created_at: '2026-09-20T10:00:00+00:00' });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText(new RegExp(id.preorders.created_at_label))).toBeInTheDocument();
  });

  // 024-invoice-layout-shipping-slip (US2, FR-005) — the modal's own title
  // reads as a pre-order invoice, not just the bare number.
  it('titles the document as a pre-order invoice', async () => {
    getPreorderInvoiceMock.mockResolvedValue(arrivedInvoice);
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText(new RegExp(id.preorders.document_title_invoice))).toBeInTheDocument();
  });

  // 024-invoice-layout-shipping-slip (US3, FR-006/FR-007) — payment
  // channels split into a QR column and a bank column.
  it('splits QR and bank-transfer payment channels into two columns', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      store_identity: null,
      footer_text: null,
      payment_channels: [
        { id: 1, type: 'qr_ewallet', provider: 'Gopay', account_name: 'Toko', account_number: null, qr_image_url: '/qr.png' },
        { id: 2, type: 'bank_transfer', provider: 'BCA', account_name: 'Toko', account_number: '999', qr_image_url: null },
      ],
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText(id.preorders.qr_payment_title)).toBeInTheDocument();
    expect(screen.getByText(id.preorders.bank_payment_title)).toBeInTheDocument();
  });

  it('shows only the QR column when no bank-transfer channel is configured', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      store_identity: null,
      footer_text: null,
      payment_channels: [{ id: 1, type: 'qr_ewallet', provider: 'Gopay', account_name: 'Toko', account_number: null, qr_image_url: '/qr.png' }],
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText(id.preorders.qr_payment_title)).toBeInTheDocument();
    expect(screen.queryByText(id.preorders.bank_payment_title)).not.toBeInTheDocument();
  });

  // 024-invoice-layout-shipping-slip (US4, FR-008/FR-009/FR-010) —
  // shipping slip renders ONLY for Mail Order, sourced from data already
  // on the payload (no Shipment record required).
  it('renders the shipping slip for a Mail Order preorder with no Shipment record', async () => {
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      fulfillment: 'courier',
      event_name: 'Sakana Fridge Meet & Greet',
      store_identity: { name: 'Sakana Fridge', logo_url: null, contact_person: null, contact_phone: '0811xxxx', contact_email: null, address: 'Jl. Toko No. 5' },
      customer: { name: 'Siti Aminah', phone: '0812xxxx', address: 'Jl. Melati No. 2' },
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.getByText(id.preorders.shipping_slip_title)).toBeInTheDocument();
    expect(screen.getByText(id.preorders.from_label)).toBeInTheDocument();
    expect(screen.getByText('Keychain Akatsuki, Poster Naruto')).toBeInTheDocument();
  });

  it('omits the shipping slip for a Self Pickup preorder', async () => {
    getPreorderInvoiceMock.mockResolvedValue({ ...arrivedInvoice, fulfillment: 'pickup' });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    expect(screen.queryByText(id.preorders.shipping_slip_title)).not.toBeInTheDocument();
  });

  it('opens a full-size popup when a payment channel QR is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getPreorderInvoiceMock.mockResolvedValue({
      ...arrivedInvoice,
      store_identity: null,
      footer_text: null,
      payment_channels: [{ id: 1, type: 'qr_ewallet', provider: 'Gopay', account_name: 'Sakana Fridge', account_number: null, qr_image_url: '/qr.png' }],
    });
    renderModal({ open: true, preorderId: 1 });

    await screen.findAllByText('PO-0001');
    await user.click(screen.getByRole('button', { name: 'Perbesar kode QR' }));

    const images = screen.getAllByAltText('Gopay');
    expect(images.length).toBeGreaterThan(1); // thumbnail + lightbox
  });
});
