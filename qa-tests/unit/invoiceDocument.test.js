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
