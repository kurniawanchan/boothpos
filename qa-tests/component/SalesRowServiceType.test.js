import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/tabs', () => ({ getTab: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }), uploadPaymentProof: vi.fn(), updatePaymentConfirmation: vi.fn(), getPaymentProofBlobUrl: vi.fn(), verifyPayment: vi.fn(), verifyOrderPayments: vi.fn() }));

const tx = (n, over) => ({
  key: `order:${n}`, id: n, order_number: `ORD-${n}`, status: 'completed', customer_name: null, created_at: '2026-09-01T09:00:00Z', cashier_id: 1, cashier_name: 'Kasir A',
  session_id: 1, item_count: 1, unit_count: 1, total_amount: '10000.00', cash_amount: '10000.00', noncash_amount: '0.00', payment_methods: ['cash'], outlet_names: [],
  items_preview: [], items_more: 0, payment_state: 'verified', discount_amount: '0.00', ...over,
});

describe('Penjualan — cara layanan dan asal order (090)', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    salesReport.mockResolvedValue({ totals: { order_count: 3 }, sessions: [], transactions: [
      tx(1, { service_type: 'dine_in', source: 'pos' }), tx(2, { service_type: 'takeaway', source: 'pos' }), tx(3, { service_type: 'dine_in', source: 'self_service' }), tx(4, { service_type: 'retail', source: 'pos' }),
    ] });
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['sales'] };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    router.push('/?from=2000-01-01&to=2099-12-31');
    await router.isReady();
    render(SalesView, { global: { plugins: [pinia, router] } });
  });

  it('labels each row Dine-In or Take Away, flags self-service orders, and leaves legacy retail rows plain', async () => {
    const row = async (n) => (await screen.findByText(`ORD-${n}`)).closest('tr');

    expect(within(await row(1)).getByTestId('row-service-type')).toHaveTextContent('Dine-In');
    expect(within(await row(2)).getByTestId('row-service-type')).toHaveTextContent('Take Away');
    expect(within(await row(3)).getByTestId('row-self-service')).toHaveTextContent('Mandiri');
    expect(within(await row(1)).queryByTestId('row-self-service')).not.toBeInTheDocument();
    expect(within(await row(4)).queryByTestId('row-service-type')).not.toBeInTheDocument();
  });
});
