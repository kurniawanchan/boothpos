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
  getPreorderInvoice: vi.fn().mockResolvedValue(mockPreorder),
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

  // 022-preorder-invoice-crud-overhaul (US4, FR-012) — the payment invoice
  // reuses the same store_identity/payment_channels/footer_text fields the
  // main invoice gets from GET /preorders/{id}/invoice, since this
  // component now fetches via getPreorderInvoice() rather than getPreorder().
  it('renders store identity and payment channels, mirroring the main invoice', async () => {
    const { getPreorderInvoice } = await import('../../resources/js/api/preorders');
    getPreorderInvoice.mockResolvedValueOnce({
      ...mockPreorder,
      store_identity: { name: 'Sakana Fridge', logo_url: null, contact_person: null, contact_phone: null, contact_email: null, address: null },
      payment_channels: [{ id: 1, type: 'bank_transfer', provider: 'BCA', account_name: 'Toko', account_number: '1234567890', qr_image_url: null }],
      footer_text: 'Sampai jumpa lagi!',
    });
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findByText('PO-0007');
    expect(screen.getByText('Sakana Fridge')).toBeInTheDocument();
    expect(screen.getByText('1234567890')).toBeInTheDocument();
    expect(screen.getByText('Sampai jumpa lagi!')).toBeInTheDocument();
  });

  it('distinguishes this payment from the order total with a "paid this time" label', async () => {
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findByText('PO-0007');
    expect(screen.getByText('Dibayar kali ini')).toBeInTheDocument();
  });

  // 023-event-availability-invoice-redesign (US2) — same standout block
  // as PreorderInvoiceModal.vue, since this document shares the same
  // invoice payload via getPreorderInvoice().
  it('renders the standout available-on/location block when present', async () => {
    const { getPreorderInvoice } = await import('../../resources/js/api/preorders');
    getPreorderInvoice.mockResolvedValueOnce({
      ...mockPreorder,
      event_location: 'Jakarta Convention Center',
      event_available_on_date: '2026-09-05',
    });
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findByText('PO-0007');
    expect(screen.getByText('Jakarta Convention Center')).toBeInTheDocument();
  });

  // 024-invoice-layout-shipping-slip — mirrored from PreorderInvoiceModal.vue
  // (research.md: same payload, same layout treatment on this document too).
  it('renders the "To:" recipient block and creation date', async () => {
    const { getPreorderInvoice } = await import('../../resources/js/api/preorders');
    getPreorderInvoice.mockResolvedValueOnce({
      ...mockPreorder,
      created_at: '2026-09-20T10:00:00+00:00',
      customer: { name: 'Budi Santoso', email: 'budi@example.com' },
    });
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findByText('PO-0007');
    expect(screen.getByText(id.preorders.to_label)).toBeInTheDocument();
    expect(screen.getByText('budi@example.com')).toBeInTheDocument();
    expect(screen.getByText(new RegExp(id.preorders.created_at_label))).toBeInTheDocument();
  });

  it('splits QR and bank-transfer payment channels into two columns', async () => {
    // paymentId 101 (cash) avoids colliding with this document's own
    // METHOD_LABELS['bank_transfer'] = "Transfer bank" text elsewhere on
    // the page — payment_channels' bank_payment_title uses the same words.
    const { getPreorderInvoice } = await import('../../resources/js/api/preorders');
    getPreorderInvoice.mockResolvedValueOnce({
      ...mockPreorder,
      payment_channels: [
        { id: 1, type: 'qr_ewallet', provider: 'Gopay', account_name: 'Toko', account_number: null, qr_image_url: '/qr.png' },
        { id: 2, type: 'bank_transfer', provider: 'BCA', account_name: 'Toko', account_number: '999', qr_image_url: null },
      ],
    });
    renderModal({ open: true, preorderId: 7, paymentId: 101 });

    await screen.findAllByText('PO-0007');
    expect(screen.getByText(id.preorders.qr_payment_title)).toBeInTheDocument();
    expect(screen.getAllByText(id.preorders.bank_payment_title).length).toBeGreaterThan(0);
  });

  it('renders the shipping slip for a Mail Order preorder', async () => {
    const { getPreorderInvoice } = await import('../../resources/js/api/preorders');
    getPreorderInvoice.mockResolvedValueOnce({
      ...mockPreorder,
      fulfillment: 'courier',
      store_identity: { name: 'Sakana Fridge', logo_url: null, contact_person: null, contact_phone: null, contact_email: null, address: 'Jl. Toko No. 5' },
      customer: { name: 'Budi Santoso', address: 'Jl. Melati No. 2' },
    });
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findAllByText('PO-0007');
    expect(screen.getByText(id.preorders.shipping_slip_title)).toBeInTheDocument();
  });

  it('omits the shipping slip for a Self Pickup preorder', async () => {
    const { getPreorderInvoice } = await import('../../resources/js/api/preorders');
    getPreorderInvoice.mockResolvedValueOnce({ ...mockPreorder, fulfillment: 'pickup' });
    renderModal({ open: true, preorderId: 7, paymentId: 102 });

    await screen.findAllByText('PO-0007');
    expect(screen.queryByText(id.preorders.shipping_slip_title)).not.toBeInTheDocument();
  });
});
