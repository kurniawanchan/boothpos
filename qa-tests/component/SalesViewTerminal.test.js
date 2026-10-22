import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { salesReport, exportSalesTransactions } from '../../resources/js/api/reports';
import { toLocalDateKey } from '../../resources/js/utils/date';
import { verifyOrderPayments } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
// 032 — aksi massal "Tandai terverifikasi" (dan api pembayaran yang dipakai detail transaksi).
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
  updatePaymentConfirmation: vi.fn(),
  getPaymentProofBlobUrl: vi.fn(),
  verifyPayment: vi.fn(),
  verifyOrderPayments: vi.fn(),
}));

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));

/** 064 — kolom dan filter Terminal pada halaman Sales (nama terminal yang mengambil order). */
const base = {
  discount_amount: '0.00', channel: 'offline', customer_id: null, customer_name: null, customer_phone: null, customer_email: null, void_reason: null,
  payment_state: 'verified', items_more: 0, status: 'completed', session_id: 11, cashier_id: 1, cashier_name: 'Kasir A',
  item_count: 1, unit_count: 1, total_amount: '10000.00', cash_amount: '10000.00', noncash_amount: '0.00',
  payment_methods: ['cash'], outlet_names: ['Nekoyama Studio'], items_preview: [{ name: 'Pin', qty: 1 }],
};
const T1 = { ...base, key: 'order:401', id: 401, order_number: 'ORD-401', created_at: '2026-09-01T09:00:00Z', terminal_id: 5, terminal_name: 'Kasir Depan' };
const T2 = { ...base, key: 'order:402', id: 402, order_number: 'ORD-402', created_at: '2026-09-01T10:00:00Z', terminal_id: 6, terminal_name: 'Kasir Belakang' };
const T3 = { ...base, key: 'order:403', id: 403, order_number: 'ORD-403', created_at: '2026-09-01T11:00:00Z', terminal_id: null, terminal_name: null };

const response = (transactions) => ({
  totals: { order_count: transactions.length, unit_count: 0, gross_sales: '0.00', net_sales: '0.00', pos_unit_count: 0, pos_gross_sales: '0.00', pos_net_sales: '0.00' },
  sessions: [],
  transactions,
});

async function setup(data, url = '/sales') {
  salesReport.mockResolvedValue(data);
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/sales', name: 'sales', component: { template: '<div />' } }] });
  router.push(/[?&](from|to)=/.test(url) ? url : `${url}${url.includes('?') ? '&' : '?'}from=2000-01-01&to=2099-12-31`);
  await router.isReady();
  render(SalesView, { global: { plugins: [pinia, router] } });
  await screen.findByText(data.transactions[0].order_number);

  return user;
}

const numbers = () => screen.getAllByRole('button', { name: /^ORD-\d+$/ }).map((b) => b.textContent.trim());
const rowOf = (n) => screen.getByText(n).closest('tr');

describe('SalesView — terminal (064)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows a Terminal column with the terminal name, and a dash for an order without a terminal', async () => {
    await setup(response([T1, T2, T3]));

    expect(screen.getByRole('columnheader', { name: /Terminal/ })).toBeInTheDocument();
    expect(within(rowOf('ORD-401')).getByText('Kasir Depan')).toBeInTheDocument();
    expect(within(rowOf('ORD-402')).getByText('Kasir Belakang')).toBeInTheDocument();
    expect(within(rowOf('ORD-403')).getByText('—')).toBeInTheDocument();
  });

  it('offers a terminal filter only when there are at least two terminals, and it narrows the list', async () => {
    const user = await setup(response([T1, T2, T3]));

    expect(screen.getByTestId('filter-terminal')).toBeInTheDocument();
    await user.click(within(screen.getByTestId('filter-terminal')).getByRole('button'));
    await user.click(await screen.findByRole('option', { name: 'Kasir Belakang' }));

    expect(numbers()).toEqual(['ORD-402']);
  });

  it('hides the filter (not disables it) with a single terminal', async () => {
    await setup(response([T1, { ...T3, id: 404, key: 'order:404', order_number: 'ORD-404' }]));

    expect(screen.queryByTestId('filter-terminal')).not.toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: /Terminal/ })).toBeInTheDocument();
  });

  it('has neither the column nor the filter when no order has a terminal', async () => {
    await setup(response([T3]));

    expect(screen.queryByRole('columnheader', { name: /Terminal/ })).not.toBeInTheDocument();
    expect(screen.queryByTestId('filter-terminal')).not.toBeInTheDocument();
  });

  it('restores the terminal filter from the URL', async () => {
    await setup(response([T1, T2, T3]), '/sales?terminal=5');

    expect(numbers()).toEqual(['ORD-401']);
  });
});
