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

const seller = (id, name, status, extra = {}) => ({ artist_id: id, name, status, stale: false, approver_name: status === 'approved' ? 'Budi' : null, method: status === 'approved' ? 'stamp' : null, approved_at: status === 'approved' ? '2026-11-05T04:00:00Z' : null, on_behalf: false, ...extra });
const row = (id, approval) => ({
  key: `order:${id}`, id, order_number: `ORD-00${id}`, status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: '10000.00', artist_names: [], payment_methods: [], approval,
});

const ROWS = [
  row(1, { status: 'pending', total: 2, approved: 0, pending: 2, sellers: [seller(1, 'A', 'pending'), seller(2, 'B', 'pending')] }),
  row(2, { status: 'partial', total: 3, approved: 2, pending: 1, sellers: [seller(1, 'A', 'approved'), seller(2, 'B', 'approved'), seller(3, 'C', 'pending')] }),
  row(3, { status: 'full', total: 1, approved: 1, pending: 0, sellers: [seller(1, 'A', 'approved')] }),
  row(4, { status: 'pending', total: 1, approved: 0, pending: 1, sellers: [seller(1, 'A', 'pending')] }),
];

/** 059 (US3) — ikon status persetujuan di tiap baris Sales + filter yang bekerja pada status KESELURUHAN. */
describe('SalesView — approval icon and filter (059 US3)', () => {
  let router;

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: ROWS });
  });

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

  const shown = () => screen.getAllByRole('button', { name: /^ORD-\d+$/ }).map((b) => b.textContent.trim()).sort();

  async function pick(user, from, option) {
    await user.click(screen.getByText(from));
    await user.click(await screen.findByRole('option', { name: option }));
  }

  it('shows one small approval icon per transaction, with the right state', async () => {
    await open();
    const icons = screen.getAllByTestId('approval-icon');
    expect(icons).toHaveLength(4);
    // Daftar terbaru-dulu (created_at sama → id menurun): ORD-004, 003, 002, 001.
    expect(icons.map((i) => i.dataset.status)).toEqual(['pending', 'full', 'partial', 'pending']);
  });

  it('opening an icon shows that row\'s per-seller details and does not open the transaction', async () => {
    const user = await open();
    await user.click(screen.getAllByTestId('approval-icon')[2]); // ORD-002 (sebagian)

    const pop = await screen.findByTestId('approval-popover');
    expect(pop).toHaveTextContent('Sebagian Disetujui');
    expect(within(pop).getByTestId('approval-popover-progress')).toHaveTextContent('2/3');
    expect(screen.queryByText(/detail transaksi/i)).toBeNull();
  });

  it('the Approval filter narrows the list by OVERALL status', async () => {
    const user = await open();
    expect(shown()).toEqual(['ORD-001', 'ORD-002', 'ORD-003', 'ORD-004']);

    await pick(user, 'Semua persetujuan', 'Menunggu Persetujuan');
    expect(shown()).toEqual(['ORD-001', 'ORD-004']);

    await pick(user, 'Menunggu Persetujuan', 'Sebagian Disetujui');
    expect(shown()).toEqual(['ORD-002']);

    await pick(user, 'Sebagian Disetujui', 'Disetujui Penuh');
    expect(shown()).toEqual(['ORD-003']);

    await pick(user, 'Disetujui Penuh', 'Semua persetujuan');
    expect(shown()).toEqual(['ORD-001', 'ORD-002', 'ORD-003', 'ORD-004']);
  });

  it('counts only the filtered rows in the "Menampilkan X dari Y" line', async () => {
    const user = await open();
    await pick(user, 'Semua persetujuan', 'Disetujui Penuh');
    expect(screen.getByText(/Menampilkan 1 dari 4/)).toBeInTheDocument();
  });

  it('restores the filter from the address (?appr=)', async () => {
    await open('/sales?appr=partial');
    expect(shown()).toEqual(['ORD-002']);
  });

  it('writes the choice to the address', async () => {
    const user = await open();
    await pick(user, 'Semua persetujuan', 'Disetujui Penuh');
    await vi.waitFor(() => expect(router.currentRoute.value.query.appr).toBe('full'));
  });
});
