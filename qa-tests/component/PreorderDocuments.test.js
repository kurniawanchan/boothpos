import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/vue';
import { createPinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import PreorderInvoiceDocument from '../../resources/js/components/preorder/PreorderInvoiceDocument.vue';
import PreorderPaymentDocument from '../../resources/js/components/preorder/PreorderPaymentDocument.vue';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

/**
 * 029-fix-bulk-invoice-logo — dua komponen dokumen ini dipakai modalnya DAN unduh
 * massal (PreordersView.mountBulkDocument), jadi aturan yang diandalkan unduhan
 * dikunci di sini: teks "Powered by …" (nama aplikasi diketik pengguna, tidak boleh
 * menjadi HTML), logo toko di header SEBELUM gambar QR, gambar QR hanya di bagian
 * pembayaran, dan tidak ada gambar di slot logo bila toko belum punya logo.
 */
const LOGO = 'http://example.test/storage/store-logo/logo.png';
const QR = 'http://example.test/storage/payment-channels/qris.jpg';

const invoice = (overrides = {}) => ({
  id: 1,
  preorder_number: 'PO-0001',
  status: 'dp_paid',
  document_type: 'invoice',
  items: [{ id: 1, name_snapshot: 'Keychain', qty: 1, sell_price: '100000.00', line_total: '100000.00' }],
  total_amount: '100000.00',
  paid_amount: '50000.00',
  outstanding: '50000.00',
  store_identity: { name: 'Toko Saya', logo_url: LOGO },
  customer: { name: 'Siti' },
  payment_channels: [
    { id: 1, type: 'qr_ewallet', provider: 'Shopee', account_name: 'Sapphirefiless', qr_image_url: QR },
    { id: 2, type: 'bank_transfer', provider: 'BCA', account_number: '8010591199' },
  ],
  payments: [{ id: 7, method: 'cash', purpose: 'down_payment', amount: '50000.00', paid_at: '2026-09-01T10:00:00Z' }],
  ...overrides,
});

function mount(component, props) {
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id, en } });
  return render(component, { props, global: { plugins: [createPinia(), i18n] } });
}

const imgSrcs = (container) => [...container.querySelectorAll('img')].map((i) => i.getAttribute('src'));

describe.each([
  ['PreorderInvoiceDocument', PreorderInvoiceDocument],
  ['PreorderPaymentDocument', PreorderPaymentDocument],
])('%s', (_name, component) => {
  it('shows "Powered by" with the app name from the payload, defaulting to BoothPOS', () => {
    expect(mount(component, { invoice: invoice({ app_name: 'Kasir Sakana' }) }).getByText('Powered by Kasir Sakana')).toBeInTheDocument();
    expect(mount(component, { invoice: invoice() }).getByText('Powered by BoothPOS')).toBeInTheDocument();
    expect(mount(component, { invoice: invoice({ app_name: '' }) }).getAllByText('Powered by BoothPOS').length).toBeGreaterThan(0);
  });

  it('renders a user-typed app name as text, never as markup', () => {
    const { container } = mount(component, { invoice: invoice({ app_name: '<img src=x onerror=alert(1)>' }) });

    expect(container.querySelector('img[src="x"]')).toBeNull();
    expect(container.textContent).toContain('Powered by <img src=x onerror=alert(1)>');
  });

  it('puts the store logo in the header before the QR, which only appears in the payment section', () => {
    const { container, getByText } = mount(component, { invoice: invoice() });

    expect(imgSrcs(container)).toEqual([LOGO, QR]);
    const html = container.innerHTML;
    expect(html.indexOf(LOGO)).toBeLessThan(html.indexOf(getByText('Shopee').outerHTML));
    expect(html.indexOf(QR)).toBeGreaterThan(html.indexOf('Cara pembayaran'));
  });

  it('leaves the logo position without any image when the store has no logo (the QR never takes its place)', () => {
    const { container } = mount(component, { invoice: invoice({ store_identity: { name: 'Toko Saya' } }) });

    expect(imgSrcs(container)).toEqual([QR]);
  });

  it('shows no image in the payment section when no QR image is configured', () => {
    const { container } = mount(component, {
      invoice: invoice({ payment_channels: [{ id: 1, type: 'qr_ewallet', provider: 'Shopee' }, { id: 2, type: 'bank_transfer', provider: 'BCA', account_number: '1' }] }),
    });

    expect(imgSrcs(container)).toEqual([LOGO]);
  });

  it('shows the BCA account number unmasked (a customer needs it to pay)', () => {
    expect(mount(component, { invoice: invoice() }).getByText('8010591199')).toBeInTheDocument();
  });
});

describe('PreorderPaymentDocument — which payment it shows', () => {
  const payments = [
    { id: 1, method: 'cash', purpose: 'down_payment', amount: '30000.00', paid_at: '2026-09-01T10:00:00Z' },
    { id: 2, method: 'cash', purpose: 'settlement', amount: '70000.00', paid_at: '2026-09-02T10:00:00Z' },
  ];

  it('falls back to the LATEST payment when no paymentId is given (bulk download)', () => {
    const { container } = mount(PreorderPaymentDocument, { invoice: invoice({ payments }) });

    expect(container.textContent).toContain('Rp 70.000');
  });

  it('shows the payment picked by paymentId', () => {
    const { container } = mount(PreorderPaymentDocument, { invoice: invoice({ payments }), paymentId: 1 });

    expect(container.textContent).toContain('Rp 30.000');
  });

  it('renders without the "paid this time" block when the order has no payment at all', () => {
    const { container } = mount(PreorderPaymentDocument, { invoice: invoice({ payments: [] }) });

    expect(container.textContent).toContain('Toko Saya');
    expect(container.textContent).not.toContain('undefined');
  });
});
