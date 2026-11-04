import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
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

const base = { status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 2, payment_methods: [], has_reference: false };
const ROWS = [
  { ...base, key: 'order:1', id: 1, order_number: 'ORD-001', artist_names: ['Alpha', 'Beta'], unit_count: 3, total_amount: '120000.00',
    items_gross: '120000.00', items_net: '120000.00', seller_items: { Alpha: { units: 1, gross: '50000.00', net: '50000.00' }, Beta: { units: 2, gross: '70000.00', net: '70000.00' } } },
];

describe('SalesView — seller click scopes the statistics (056 US9)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: ROWS });
  });

  it('clicking a seller name makes Gross/Net count only that seller\'s item', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');

    const cards = screen.getByTestId('kpi-cards');
    expect(cards.textContent).toMatch(/120\.000/);

    await user.click(screen.getByRole('button', { name: 'Alpha' }));

    expect(within(screen.getByTestId('kpi-cards')).queryByText(/120\.000/)).toBeNull();
    expect(screen.getByTestId('kpi-cards').textContent).toMatch(/50\.000/);
    expect(screen.getByTestId('kpi-cards').textContent).not.toMatch(/70\.000/);
  });
});
