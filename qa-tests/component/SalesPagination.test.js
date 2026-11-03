import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const row = (i) => ({
  key: `order:${i}`, id: i, order_number: `ORD-${String(i).padStart(3, '0')}`, status: 'completed', customer_name: null,
  created_at: `2026-09-01T${String(Math.floor(i / 60)).padStart(2, '0')}:${String(i % 60).padStart(2, '0')}:00Z`,
  cashier_id: 1, cashier_name: 'Kasir A', session_id: 1, item_count: 1, unit_count: 1, total_amount: '10000.00',
  items_gross: '10000.00', items_net: '10000.00', cash_amount: '10000.00', noncash_amount: '0.00', payment_methods: ['cash'],
  artist_names: ['Seller'], items_preview: [], items_more: 0, payment_state: 'verified', discount_amount: '0.00',
});
const ROWS = Array.from({ length: 60 }, (_, i) => row(i + 1));
const RESPONSE = { event: { id: 1, name: 'Event A' }, totals: {}, sessions: [], transactions: ROWS };

let router;
async function open(url = '/sales') {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  const pinia = createPinia();
  setActivePinia(pinia);
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/sales', name: 'sales', component: { template: '<div />' } }] });
  router.push(url);
  await router.isReady();
  render(SalesView, { global: { plugins: [pinia, router] } });
  await screen.findAllByRole('button', { name: /^ORD-\d+$/ });
  return user;
}
const numbers = () => screen.getAllByRole('button', { name: /^ORD-\d+$/ }).map((b) => b.textContent.trim());

// 054 (US5) — tabel Sales berhalaman, kartu tetap mencakup semua baris yang lolos filter.
describe('SalesView — pagination (054 US5)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(RESPONSE);
  });

  it('shows 25 rows, the page indicator, and the cards for all 60 rows', async () => {
    await open();

    expect(numbers()).toHaveLength(25);
    expect(screen.getByText('1 / 3')).toBeInTheDocument();
    expect(within(screen.getByTestId('kpi-cards')).getByText('Transaksi').nextElementSibling).toHaveTextContent('60');
  });

  it('moves to the next page without repeating a row and writes the page to the address', async () => {
    const user = await open();
    const first = numbers();

    await user.click(screen.getByRole('button', { name: 'Halaman berikutnya' }));

    const second = numbers();
    expect(second).toHaveLength(25);
    expect(second.some((n) => first.includes(n))).toBe(false);
    expect(screen.getByText('2 / 3')).toBeInTheDocument();
    await vi.waitFor(() => expect(router.currentRoute.value.query.page).toBe('2'));
  });

  it('restores the page from the address', async () => {
    await open('/sales?page=3');

    expect(numbers()).toHaveLength(10);
    expect(screen.getByText('3 / 3')).toBeInTheDocument();
  });

  it('does not show the pager for a short list', async () => {
    salesReport.mockResolvedValue({ ...RESPONSE, transactions: ROWS.slice(0, 5) });
    await open();

    expect(numbers()).toHaveLength(5);
    expect(screen.queryByRole('button', { name: 'Halaman berikutnya' })).toBeInTheDocument(); // pager tetap tampil (1 / 1), tombol nonaktif
    expect(screen.getByRole('button', { name: 'Halaman berikutnya' })).toBeDisabled();
  });

  it('expands "+N more" in place to list every item and collapses again', async () => {
    const withMore = { ...ROWS[59], items_preview: [{ name: 'A', qty: 1 }, { name: 'B', qty: 1 }], items_more: 1, items_rest: [{ name: 'Gamma extra', qty: 2 }] };
    salesReport.mockResolvedValue({ ...RESPONSE, transactions: [...ROWS.slice(0, 59), withMore] });
    const user = await open();

    expect(screen.queryByText(/Gamma extra/)).not.toBeInTheDocument();
    await user.click(screen.getByTestId('items-more'));
    expect(screen.getByText(/Gamma extra/)).toBeInTheDocument();
    expect(screen.getByTestId('items-more')).toHaveAttribute('aria-expanded', 'true');

    await user.click(screen.getByTestId('items-more'));
    expect(screen.queryByText(/Gamma extra/)).not.toBeInTheDocument();
  });
});
