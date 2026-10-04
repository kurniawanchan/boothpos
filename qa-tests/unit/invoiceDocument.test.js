import { describe, it, expect } from 'vitest';
import { buildInvoiceHtml } from '../../resources/js/utils/invoiceDocument';

/**
 * buildInvoiceHtml() dipakai unduhan massal (dokumen dirender dari payload, bukan
 * dari komponen modal) — jadi teks "Powered by …" harus ikut ada di sini,
 * dan nama aplikasi (diketik pengguna) HARUS di-escape.
 */
const invoice = (overrides = {}) => ({
  preorder_number: 'PO-0001',
  status: 'ordered',
  total_amount: '100000.00',
  paid_amount: '0.00',
  outstanding: '100000.00',
  store_identity: { name: 'Toko Saya' },
  customer: { name: 'Siti' },
  items: [{ qty: 1, name_snapshot: 'Keychain', line_total: '100000.00' }],
  payment_channels: [],
  payments: [],
  ...overrides,
});

describe('buildInvoiceHtml — powered by', () => {
  it('shows "Powered by" with the app name from the payload', () => {
    const html = buildInvoiceHtml(invoice({ app_name: 'Kasir Sakana' }));

    expect(html).toContain('Powered by Kasir Sakana');
  });

  it('falls back to BoothPOS when the payload carries no app name', () => {
    expect(buildInvoiceHtml(invoice())).toContain('Powered by BoothPOS');
    expect(buildInvoiceHtml(invoice({ app_name: '' }))).toContain('Powered by BoothPOS');
  });

  it('escapes the app name so a user-typed name cannot inject markup', () => {
    const html = buildInvoiceHtml(invoice({ app_name: '<img src=x onerror=alert(1)>' }));

    expect(html).not.toContain('<img src=x');
    expect(html).toContain('Powered by &lt;img src=x onerror=alert(1)&gt;');
  });

  it('is on the payment invoice document too', () => {
    const html = buildInvoiceHtml(
      invoice({ app_name: 'Kasir Sakana', payments: [{ id: 1, method: 'cash', purpose: 'down_payment', amount: '50000.00', paid_at: '2026-09-01T10:00:00Z' }] }),
      'payment_invoice',
    );

    expect(html).toContain('Powered by Kasir Sakana');
  });
});

// 029-fix-bulk-invoice-logo (US1) — struktur dokumen yang diandalkan langkah unduh:
// logo toko di header, gambar QR HANYA di bagian pembayaran dekat nama penyedianya.
describe('buildInvoiceHtml — where the images sit (029)', () => {
  const LOGO = 'http://example.test/storage/store-logo/logo.png';
  const QR = 'http://example.test/storage/payment-channels/qris.jpg';
  const withImages = (overrides = {}) => invoice({
    store_identity: { name: 'Toko Saya', logo_url: LOGO },
    payment_channels: [
      { type: 'qr_ewallet', provider: 'Shopee', account_name: 'Sapphirefiless', qr_image_url: QR },
      { type: 'bank_transfer', provider: 'BCA', account_number: '8010591199' },
    ],
    ...overrides,
  });
  const imgSrcs = (html) => [...html.matchAll(/<img[^>]*\ssrc="([^"]*)"/g)].map((m) => m[1]);
  const at = (html, needle) => html.indexOf(needle);

  it.each(['invoice', 'payment_invoice'])('puts the store logo in the header, before the payment section, for the %s document', (doc) => {
    const html = buildInvoiceHtml(withImages(), doc);

    expect(imgSrcs(html)).toEqual([LOGO, QR]);
    expect(at(html, LOGO)).toBeLessThan(at(html, 'Cara pembayaran'));
    expect(at(html, QR)).toBeGreaterThan(at(html, 'Cara pembayaran'));
  });

  it('shows the QR image right above its own provider name', () => {
    const html = buildInvoiceHtml(withImages());

    const qrSection = html.slice(at(html, QR));
    expect(qrSection.indexOf('Shopee')).toBeGreaterThan(-1);
    expect(qrSection.indexOf('Shopee')).toBeLessThan(qrSection.indexOf('Transfer bank'));
  });

  it('leaves the logo position without any image when the store has no logo (the QR never takes its place)', () => {
    const html = buildInvoiceHtml(withImages({ store_identity: { name: 'Toko Saya' } }));

    expect(imgSrcs(html)).toEqual([QR]);
    expect(at(html, QR)).toBeGreaterThan(at(html, 'Cara pembayaran'));
  });

  it('shows no image in the payment section when no QR image is configured', () => {
    const html = buildInvoiceHtml(withImages({
      payment_channels: [{ type: 'qr_ewallet', provider: 'Shopee' }, { type: 'bank_transfer', provider: 'BCA', account_number: '1' }],
    }));

    expect(imgSrcs(html)).toEqual([LOGO]);
  });

  it('renders each invoice of a batch with its own images (no state shared between builds)', () => {
    const first = buildInvoiceHtml(withImages({ preorder_number: 'PO-1' }));
    const second = buildInvoiceHtml(withImages({
      preorder_number: 'PO-2',
      store_identity: { name: 'Toko Saya', logo_url: 'http://example.test/other-logo.png' },
    }));

    expect(imgSrcs(first)).toEqual([LOGO, QR]);
    expect(imgSrcs(second)).toEqual(['http://example.test/other-logo.png', QR]);
  });
});
