import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SessionsHistoryView from '../../resources/js/views/SessionsHistoryView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listSessions, getSession, sessionTransactions } from '../../resources/js/api/sessions';
import { listEvents } from '../../resources/js/api/events';
import { listUsers } from '../../resources/js/api/users';

vi.mock('../../resources/js/api/sessions', () => ({ listSessions: vi.fn(), getSession: vi.fn(), sessionTransactions: vi.fn() }));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/users', () => ({ listUsers: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const ROW = (id, over = {}) => ({ id, cashier_id: 2, cashier_name: 'Kasir A', event_id: 1, event_name: 'Sakana Fest', opened_at: '2026-11-01T02:00:00Z', closed_at: '2026-11-01T10:00:00Z', status: 'closed', opening_cash: '100000.00', ...over });
const META = (over = {}) => ({ current_page: 1, per_page: 25, total: 2, last_page: 1, ...over });

const CLOSED_DETAIL = { ...ROW(1), expected_cash: '150000.00', closing_cash: '145000.00', cash_difference: '-5000.00', order_count: 2, total_sales: '100000.00', by_method: [{ method: 'cash', count: 2, amount: '100000.00' }], opening_cash_entries: [] };
const OPEN_DETAIL = { ...ROW(2, { status: 'open', closed_at: null }), expected_cash: '200000.00', closing_cash: null, cash_difference: null, order_count: 1, total_sales: '50000.00', by_method: [], opening_cash_entries: [] };

function renderView(menuKeys = ['session', 'settings']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  return render(SessionsHistoryView, { global: { plugins: [pinia] } });
}

// 054 (US12) — riwayat shift kasir.
describe('SessionsHistoryView (054 US12)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listSessions.mockResolvedValue({ data: [ROW(1), ROW(2, { status: 'open', closed_at: null, cashier_name: 'Kasir B' })], meta: META() });
    getSession.mockImplementation(async (id) => (id === 1 ? CLOSED_DETAIL : OPEN_DETAIL));
    sessionTransactions.mockResolvedValue({
      data: [{ kind: 'sale', order_id: 77, order_number: 'ORD-077', created_at: '2026-11-01T03:00:00Z', cashier_name: 'Kasir A', amount: '50000.00', status: 'completed', method: null }],
      meta: META({ total: 1 }),
    });
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Sakana Fest' }] });
    listUsers.mockResolvedValue({ data: [{ id: 2, name: 'Kasir A' }, { id: 3, name: 'Kasir B' }] });
  });

  it('lists sessions with cashier, event, status and opening cash; an open one has no closing time', async () => {
    renderView();

    const closed = (await screen.findByText('Kasir A')).closest('tr');
    expect(within(closed).getByText('Sakana Fest')).toBeInTheDocument();
    expect(within(closed).getByText(/100\.000/)).toBeInTheDocument();
    const open = screen.getByText('Kasir B').closest('tr');
    expect(within(open).getByText('Terbuka')).toBeInTheDocument();
    expect(within(open).queryByText(/2026|01 Nov|1 Nov/)).toBeInTheDocument(); // waktu buka tetap tampil
  });

  it('reloads from page 1 when a filter changes', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('Kasir A');

    await user.click(screen.getByText('Semua status'));
    await user.click(await screen.findByRole('option', { name: 'Terbuka' }));

    await waitFor(() => expect(listSessions).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'open', page: 1 })));
  });

  it('pages through the list', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listSessions.mockResolvedValue({ data: [ROW(1)], meta: META({ total: 60, last_page: 3 }) });
    renderView();
    await screen.findByText('Kasir A');

    await user.click(screen.getByRole('button', { name: 'Halaman berikutnya' }));

    await waitFor(() => expect(listSessions).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })));
  });

  it('shows the cashier filter only to users who manage the store', async () => {
    renderView(['session']);
    await screen.findByText('Kasir A');
    expect(screen.queryByText('Semua kasir')).not.toBeInTheDocument();
    expect(listUsers).not.toHaveBeenCalled();
  });

  it('opens a closed session: opening, expected, counted cash and the difference, plus its transactions', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    const row = (await screen.findByText('Kasir A')).closest('tr');

    await user.click(within(row).getByRole('button', { name: /lihat detail/i }));

    const detail = await screen.findByTestId('session-detail');
    // Tiap angka dibaca dari kartunya sendiri (regex "5.000" juga cocok dengan 145.000 dan 50.000).
    const card = (label) => within(detail).getByText(label).parentElement;
    await within(detail).findByText('Kas diharapkan');
    expect(card('Kas diharapkan')).toHaveTextContent(/150\.000/);
    expect(card('Kas dihitung')).toHaveTextContent(/145\.000/);
    expect(card('Selisih')).toHaveTextContent(/5\.000/);
    expect(card('Kas awal')).toHaveTextContent(/100\.000/);
    expect(await within(detail).findByRole('button', { name: 'ORD-077' })).toBeInTheDocument();
    expect(getSession).toHaveBeenCalledWith(1);
    expect(sessionTransactions).toHaveBeenCalledWith(1, expect.objectContaining({ page: 1 }));
  });

  it('says an open session is not closed yet and shows the expected cash so far', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    const row = (await screen.findByText('Kasir B')).closest('tr');

    await user.click(within(row).getByRole('button', { name: /lihat detail/i }));

    const detail = await screen.findByTestId('session-detail');
    expect(await within(detail).findByText(/belum ditutup/i)).toBeInTheDocument();
    expect(within(detail).getByText(/200\.000/)).toBeInTheDocument();
    expect(within(detail).queryByText(/selisih/i)).not.toBeInTheDocument();
  });

  it('marks a payment received from another shift differently from a sale', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    sessionTransactions.mockResolvedValue({
      data: [{ kind: 'payment_received', order_id: 5, order_number: 'ORD-005', created_at: '2026-11-01T05:00:00Z', cashier_name: 'Kasir A', amount: '30000.00', status: 'completed', method: 'cash' }],
      meta: META({ total: 1 }),
    });
    renderView();
    const row = (await screen.findByText('Kasir A')).closest('tr');
    await user.click(within(row).getByRole('button', { name: /lihat detail/i }));

    expect(await screen.findByText(/pembayaran diterima/i)).toBeInTheDocument();
  });

  it('shows an empty state when there are no sessions', async () => {
    listSessions.mockResolvedValueOnce({ data: [], meta: META({ total: 0 }) });
    renderView();
    await waitFor(() => expect(listSessions).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByText('Belum ada sesi kasir.')).toBeInTheDocument());
  });
});
