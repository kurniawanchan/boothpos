import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { salesReport } from '../../resources/js/api/reports';
import { getFloor } from '../../resources/js/api/floor';
import { listSelfServiceOrders } from '../../resources/js/api/selfServiceOrders';

vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/tabs', () => ({ getTab: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }), uploadPaymentProof: vi.fn(), updatePaymentConfirmation: vi.fn(),
  getPaymentProofBlobUrl: vi.fn(), verifyPayment: vi.fn(), verifyOrderPayments: vi.fn(),
}));
vi.mock('../../resources/js/api/selfServiceOrders', () => ({
  listSelfServiceOrders: vi.fn(), getSelfServiceOrder: vi.fn(), verifySelfServiceOrder: vi.fn(), rejectSelfServiceOrder: vi.fn(),
  dispatchSelfServiceOrder: vi.fn(), fetchProofBlob: vi.fn(),
}));

// 090 / US6 — tampilan ketiga "Self-Service" di halaman Penjualan: hanya bagi pemegang menu `self_service`, dengan lencana
// jumlah pesanan yang menunggu, dan pesanan mandiri TIDAK bercampur dengan daftar order kasir.
const TX = {
  key: 'order:401', id: 401, order_number: 'ORD-401', status: 'completed', customer_name: null, created_at: '2026-09-01T09:00:00Z',
  cashier_id: 1, cashier_name: 'Kasir A', session_id: 1, item_count: 1, unit_count: 1, total_amount: '10000.00', cash_amount: '10000.00',
  noncash_amount: '0.00', payment_methods: ['cash'], outlet_names: [], items_preview: [], items_more: 0, payment_state: 'verified', discount_amount: '0.00',
};
const ss = (over = {}) => ({
  id: 1, reference_no: 'SS-251103-001', status: 'awaiting_verification', created_at: '2026-11-03T05:00:00+00:00', table: { id: 5, name: '5' },
  service_type: 'dine_in', item_count: 1, total_amount: '25000.00', payment: { status: 'waiting_verification', proof_id: 1 }, ...over,
});
const page = (rows, pending) => ({ data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1, counts: { awaiting_verification: pending, verified: 0, dispatched: 0, rejected: 0, pending } } });

async function mountSales({ menus = ['sales', 'self_service'], url = '/' } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: menus, permissions: [] };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  router.push(`${url}${url.includes('?') ? '&' : '?'}from=2000-01-01&to=2099-12-31`);
  await router.isReady();
  render(SalesView, { global: { plugins: [pinia, router] } });
  return { router, user: userEvent.setup() };
}

describe('SalesView — tab Self-Service (090)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    salesReport.mockResolvedValue({ totals: { order_count: 1 }, sessions: [], transactions: [TX] });
    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v0' });
    listSelfServiceOrders.mockResolvedValue(page([ss()], 3));
  });

  it('offers the Self-Service segment with a badge of the orders waiting for action', async () => {
    await mountSales();
    await screen.findByText('ORD-401');

    expect(screen.getByRole('radio', { name: /Self-Service/ })).toBeInTheDocument();
    expect(await screen.findByTestId('view-badge-self-service')).toHaveTextContent('3');
    expect(screen.queryByRole('radio', { name: 'Meja' })).not.toBeInTheDocument(); // tanpa akses lantai
  });

  it('hides the segment, and never asks the server, from roles without the menu', async () => {
    await mountSales({ menus: ['sales'] });
    await screen.findByText('ORD-401');

    expect(screen.queryByRole('radio', { name: /Self-Service/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('radiogroup')).not.toBeInTheDocument(); // satu pilihan saja → tanpa saklar
    expect(listSelfServiceOrders).not.toHaveBeenCalled();
  });

  it('shows the queue in its own view and keeps cashier sales out of it', async () => {
    const { user, router } = await mountSales();
    await screen.findByText('ORD-401');

    await user.click(screen.getByRole('radio', { name: /Self-Service/ }));

    expect(await screen.findByTestId('self-service-panel')).toBeInTheDocument();
    expect(await screen.findByText('SS-251103-001')).toBeInTheDocument();
    expect(screen.queryByText('ORD-401')).not.toBeInTheDocument();
    expect(screen.queryByTestId('kpi-cards')).not.toBeInTheDocument();
    expect(router.currentRoute.value.query.view).toBe('self-service');
  });

  it('opens straight into the queue from a saved link, and falls back to the list without the menu', async () => {
    await mountSales({ url: '/?view=self-service' });
    expect(await screen.findByTestId('self-service-panel')).toBeInTheDocument();
  });

  it('ignores ?view=self-service for a role without the menu', async () => {
    await mountSales({ menus: ['sales'], url: '/?view=self-service' });

    expect(await screen.findByText('ORD-401')).toBeInTheDocument();
    expect(screen.queryByTestId('self-service-panel')).not.toBeInTheDocument();
  });

  it('keeps the badge current from the panel after it loads', async () => {
    const { user } = await mountSales();
    await screen.findByText('ORD-401');
    listSelfServiceOrders.mockResolvedValue(page([ss(), ss({ id: 2, reference_no: 'SS-251103-002' })], 5));

    await user.click(screen.getByRole('radio', { name: /Self-Service/ }));

    await waitFor(() => expect(screen.getByTestId('view-badge-self-service')).toHaveTextContent('5'));
  });
});

describe('SalesView — lencana Self-Service saat server menolak (090)', () => {
  it('stops polling after a permanent refusal instead of hammering the server', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const { ApiError } = await import('../../resources/js/utils/errors');
    salesReport.mockResolvedValue({ totals: { order_count: 0 }, sessions: [], transactions: [] });
    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v0' });
    listSelfServiceOrders.mockReset();
    listSelfServiceOrders.mockRejectedValue(new ApiError('skema tertinggal', { status: 503, code: 'schema_outdated' }));
    await mountSales();
    await waitFor(() => expect(listSelfServiceOrders).toHaveBeenCalledTimes(1));

    await vi.advanceTimersByTimeAsync(120000);

    expect(listSelfServiceOrders).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
  });
});
