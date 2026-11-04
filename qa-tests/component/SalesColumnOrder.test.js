import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ verifyOrderPayments: vi.fn(), getPaymentProofBlobUrl: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const row = (id, total, margin) => ({
  key: `order:${id}`, id, order_number: `ORD-00${id}`, status: 'completed', created_at: `2026-09-0${id}T10:00:00Z`, cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: total, artist_names: [], payment_methods: [], has_reference: false,
  ...(margin === undefined ? {} : { margin_amount: margin, margin_percent: 10 }),
});

/**
 * 056 (US5) — urutan kolom: Total SEBELUM Margin. Margin hanya ada bila server mengirimnya (owner/admin);
 * untuk kasir Total menjadi kolom data terakhir. Kunci pengurutan tiap kolom tidak berubah.
 */
describe('SalesView — column order (056 US5)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
  });

  async function open(transactions) {
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');
    return user;
  }

  const headers = () => screen.getAllByRole('columnheader').map((h) => h.textContent.trim());
  const order = () => screen.getAllByRole('button', { name: /^ORD-\d+$/ }).map((b) => b.textContent.trim());

  it('puts Total immediately before Margin for owner/admin', async () => {
    await open([row(1, '10000.00', '1000.00'), row(2, '30000.00', '5000.00')]);

    const h = headers();
    expect(h.indexOf('Total')).toBeGreaterThan(-1);
    expect(h.indexOf('Margin')).toBe(h.indexOf('Total') + 1);
    expect(h.indexOf('Pembayaran')).toBe(h.indexOf('Total') - 1);
  });

  it('has no Margin column for a cashier, and Total is the last data column', async () => {
    await open([row(1, '10000.00'), row(2, '30000.00')]);

    const h = headers();
    expect(h).not.toContain('Margin');
    expect(h.filter(Boolean).at(-1)).toBe('Total');
  });

  it('still sorts each of the two columns by its own data after the swap', async () => {
    // Urutan Total (naik): 1(10rb) < 3(20rb) < 2(30rb). Urutan Margin (naik): 2(1rb) < 1(2rb) < 3(9rb) — sengaja berbeda.
    const user = await open([row(1, '10000.00', '2000.00'), row(2, '30000.00', '1000.00'), row(3, '20000.00', '9000.00')]);

    await user.click(screen.getByRole('columnheader', { name: /^Total/ }));
    expect(order()).toEqual(['ORD-001', 'ORD-003', 'ORD-002']);

    await user.click(screen.getByRole('columnheader', { name: /^Margin/ }));
    expect(order()).toEqual(['ORD-002', 'ORD-001', 'ORD-003']);
  });
});
