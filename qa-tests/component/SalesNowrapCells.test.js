import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

// 054 (US6) — nomor transaksi dan total tidak boleh terbelah baris (pola yang sama dengan fitur 053).
describe('SalesView — one-line number and total (054 US6)', () => {
  beforeEach(() => {
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({
      event: null, totals: {}, sessions: [],
      transactions: [{ key: 'order:1', id: 1, order_number: 'POS-20261101-0001', status: 'completed', created_at: '2026-11-01T10:00:00Z', cashier_name: 'K', item_count: 1, unit_count: 1, total_amount: '1234500.00', artist_names: [], payment_methods: [] }],
    });
  });

  it('keeps the transaction number and the total on a single line', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });

    const number = await screen.findByRole('button', { name: 'POS-20261101-0001' });
    expect(number.closest('td').querySelector('.whitespace-nowrap')).not.toBeNull();
    expect(number.className + number.closest('div').className).toMatch(/whitespace-nowrap/);

    const total = screen.getByText(/Rp\s*1\.234\.500/, { selector: 'td span, td' });
    expect(total.className).toMatch(/whitespace-nowrap/);
  });
});
