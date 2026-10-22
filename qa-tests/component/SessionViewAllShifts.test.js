import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SessionView from '../../resources/js/views/SessionView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { currentSession, listSessions, listCashMovements, sessionSummary, sessionCashMovements } from '../../resources/js/api/sessions';

vi.mock('../../resources/js/api/sessions', () => ({
  currentSession: vi.fn(), sessionSummary: vi.fn(), closeSession: vi.fn(), openSession: vi.fn(),
  listSessions: vi.fn(), listCashMovements: vi.fn(), sessionCashMovements: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn().mockResolvedValue({ data: [] }) }));

const OPEN = { id: 11, source: 'app', terminal_name: 'Kasir Depan', cashier: { id: 1, name: 'Dewi' }, outlet: { id: 1, name: 'WJM' }, status: 'open', opened_at: '2026-10-22T06:39:00+07:00', closed_at: null, opening_cash: '100000.00', order_count: 3, sales_total: '37400.00', expected_cash: '137400.00', counted_total: null, difference: null };
const CLOSED = { ...OPEN, id: 21, status: 'closed', closed_at: '2026-10-22T22:00:00+07:00', counted_total: '135000.00', difference: '-2400.00' };
const META = { current_page: 1, per_page: 25, total: 1, last_page: 1 };

async function renderPage(role) {
  const pinia = createPinia();
  setActivePinia(pinia);
  // Cermin aturan server: owner/admin = punya menu `settings`; peran lain tidak.
  const manager = ['owner', 'admin'].includes(role.toLowerCase());
  useAuthStore().user = { id: 1, name: 'T', username: 't', role, menu_keys: manager ? ['session', 'settings'] : ['session'] };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: SessionView }] });
  router.push('/');
  await router.isReady();
  return render(SessionView, { global: { plugins: [pinia, router] } });
}

describe('SessionView — all shifts for owner/admin (065)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    listSessions.mockImplementation(async (p) => ({ data: p.status === 'closed' ? [CLOSED] : [OPEN], meta: META }));
    listCashMovements.mockResolvedValue({ data: [{ id: 1, direction: 'out', reason_code: 'petty_cash', reason_label: 'Uang kecil / belanja', reason_note: null, amount: '50000.00', created_by_name: 'Dewi', created_at: '2026-10-22T10:00:00+07:00', source: 'app', terminal_name: 'Kasir Depan' }], meta: META, totals: { in: '0.00', out: '50000.00' } });
    sessionSummary.mockResolvedValue({ session: { id: 11, status: 'open', opening_cash: '100000.00', opened_at: '2026-10-22T06:39:00+07:00', user: { name: 'Dewi' } }, terminal_name: 'Kasir Depan', order_count: 3, total_sales: '37400.00', expected_cash: '137400.00', cash_in: '0.00', cash_out: '50000.00', by_method: [{ method: 'cash', count: 3, amount: '37400.00' }] });
    sessionCashMovements.mockResolvedValue([{ id: 1, direction: 'out', reason_code: 'petty_cash', reason_label: 'Uang kecil / belanja', reason_note: null, amount: '50000.00', created_by_name: 'Dewi', created_at: '2026-10-22T10:00:00+07:00' }]);
  });

  it('shows the open-shifts list with Open / Closed / Cash tabs to an owner', async () => {
    await renderPage('Owner');

    const section = await screen.findByTestId('all-shifts');
    expect(await within(section).findByText('Dewi')).toBeInTheDocument();
    expect(within(section).getByRole('tab', { name: /terbuka|open/i })).toBeInTheDocument();
    expect(within(section).getByRole('tab', { name: /tertutup|closed/i })).toBeInTheDocument();
    expect(within(section).getByRole('tab', { name: /kas masuk|cash in/i })).toBeInTheDocument();
    expect(listSessions).toHaveBeenCalledWith(expect.objectContaining({ status: 'open' }));
  });

  it('hides the whole section (not disabled) for a cashier', async () => {
    await renderPage('cashier');

    await screen.findByRole('button', { name: /buka sesi|open session/i });
    expect(screen.queryByTestId('all-shifts')).not.toBeInTheDocument();
    expect(listSessions).not.toHaveBeenCalled();
  });

  it('switches to closed shifts and shows counted cash and the difference', async () => {
    await renderPage('admin');
    const section = await screen.findByTestId('all-shifts');

    await userEvent.setup().click(within(section).getByRole('tab', { name: /tertutup|closed/i }));

    await waitFor(() => expect(listSessions).toHaveBeenCalledWith(expect.objectContaining({ status: 'closed' })));
    expect(await within(section).findByText(/Rp\s?135\.000/)).toBeInTheDocument();
  });

  it('opens a shift detail with sales information and its cash entries', async () => {
    await renderPage('owner');
    const section = await screen.findByTestId('all-shifts');

    await userEvent.setup().click(await within(section).findByTestId('shift-detail-11'));

    const detail = await screen.findByTestId('shift-detail');
    expect(await within(detail).findByTestId('detail-sales')).toHaveTextContent(/37\.400/);
    expect(within(detail).getByTestId('detail-expected')).toHaveTextContent(/137\.400/);
    expect(within(detail).getByText('Uang kecil / belanja')).toBeInTheDocument();
    expect(within(detail).getByTestId('detail-methods')).toBeInTheDocument();
  });

  it('lists cash movements with totals across the whole filtered set', async () => {
    await renderPage('owner');
    const section = await screen.findByTestId('all-shifts');

    await userEvent.setup().click(within(section).getByRole('tab', { name: /kas masuk|cash in/i }));

    expect(await within(section).findByText('Uang kecil / belanja')).toBeInTheDocument();
    expect(within(section).getByTestId('cash-totals')).toHaveTextContent(/50\.000/);
  });
});
