import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SessionsHistoryView from '../../resources/js/views/SessionsHistoryView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listSessions, getSession, sessionTransactions, reopenSession, closeSession } from '../../resources/js/api/sessions';
import { listEvents } from '../../resources/js/api/events';
import { listUsers } from '../../resources/js/api/users';

vi.mock('../../resources/js/api/sessions', () => ({ listSessions: vi.fn(), getSession: vi.fn(), sessionTransactions: vi.fn(), reopenSession: vi.fn(), closeSession: vi.fn() }));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/users', () => ({ listUsers: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const ROW = (id, over = {}) => ({ id, cashier_id: 2, cashier_name: 'Kasir A', event_id: 1, event_name: 'Sakana Fest', opened_at: '2026-11-01T02:00:00Z', closed_at: '2026-11-01T10:00:00Z', status: 'closed', opening_cash: '100000.00', reopen_count: 0, ...over });
const META = { current_page: 1, per_page: 25, total: 1, last_page: 1 };
const HISTORY = [{
  id: 7, reopened_by_id: 1, reopened_by_name: 'Pak Owner', reopened_at: '2026-11-02T03:00:00Z', reason: 'Hitungan kas salah ketik',
  previous_closed_at: '2026-11-01T10:00:00Z', previous_closing_cash: '145000.00', previous_expected_cash: '150000.00', previous_cash_difference: '-5000.00',
}];
const CLOSED = (over = {}) => ({ ...ROW(1), expected_cash: '150000.00', closing_cash: '145000.00', cash_difference: '-5000.00', order_count: 2, total_sales: '100000.00', by_method: [], opening_cash_entries: [], can_reopen: true, reopens: [], ...over });
const REOPENED = { ...ROW(1, { status: 'open', closed_at: null, reopen_count: 1 }), expected_cash: '150000.00', closing_cash: null, cash_difference: null, order_count: 2, total_sales: '100000.00', by_method: [], opening_cash_entries: [], can_reopen: false, reopens: HISTORY };

function renderView() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['session', 'settings'] };
  return render(SessionsHistoryView, { global: { plugins: [pinia] } });
}

async function openDetail() {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  renderView();
  const row = (await screen.findByText('Kasir A')).closest('tr');
  await user.click(within(row).getByRole('button', { name: /lihat detail/i }));
  await screen.findByTestId('session-detail');
  return user;
}

// 063 — Owner membuka kembali shift yang sudah ditutup.
describe('SessionsHistoryView reopen (063)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listSessions.mockResolvedValue({ data: [ROW(1)], meta: META });
    sessionTransactions.mockResolvedValue({ data: [], meta: { ...META, total: 0 } });
    listEvents.mockResolvedValue({ data: [] });
    listUsers.mockResolvedValue({ data: [] });
    getSession.mockResolvedValue(CLOSED());
  });

  it('shows the reopen button only when the server says can_reopen', async () => {
    getSession.mockResolvedValue(CLOSED({ can_reopen: false }));
    await openDetail();
    expect(await screen.findByText('Kas dihitung')).toBeInTheDocument();
    expect(screen.queryByTestId('reopen-shift')).not.toBeInTheDocument();
  });

  it('requires a reason of at least 3 characters before confirming', async () => {
    const user = await openDetail();
    await user.click(await screen.findByTestId('reopen-shift'));

    const dialog = await screen.findByRole('dialog', { name: /buka kembali shift ini/i });
    const confirm = within(dialog).getByRole('button', { name: 'Buka kembali shift' });
    expect(confirm).toBeDisabled();
    await user.type(within(dialog).getByRole('textbox'), 'ab');
    expect(confirm).toBeDisabled();
    await user.type(within(dialog).getByRole('textbox'), 'c');
    expect(confirm).toBeEnabled();
    expect(reopenSession).not.toHaveBeenCalled();
  });

  it('reopens, shows the history, and reloads the list', async () => {
    reopenSession.mockResolvedValue(REOPENED);
    const user = await openDetail();
    await user.click(await screen.findByTestId('reopen-shift'));
    const dialog = await screen.findByRole('dialog', { name: /buka kembali shift ini/i });
    await user.type(within(dialog).getByRole('textbox'), '  Hitungan kas salah ketik  ');
    await user.click(within(dialog).getByRole('button', { name: 'Buka kembali shift' }));

    await waitFor(() => expect(reopenSession).toHaveBeenCalledWith(1, { reason: 'Hitungan kas salah ketik' }));
    const history = await screen.findByTestId('reopen-history');
    expect(within(history).getByText('Hitungan kas salah ketik')).toBeInTheDocument();
    expect(within(history).getByText(/Pak Owner/)).toBeInTheDocument();
    expect(within(history).getByText(/145\.000/)).toBeInTheDocument();
    expect(screen.queryByTestId('reopen-shift')).not.toBeInTheDocument();
    await waitFor(() => expect(listSessions).toHaveBeenCalledTimes(2));
    expect(screen.queryByRole('dialog', { name: /buka kembali shift ini/i })).not.toBeInTheDocument();
  });

  it('keeps the dialog open and shows the server message when the reopen is refused', async () => {
    reopenSession.mockRejectedValue({ status: 409, message: 'Kasir shift ini masih memiliki shift lain yang terbuka.' });
    const user = await openDetail();
    await user.click(await screen.findByTestId('reopen-shift'));
    const dialog = await screen.findByRole('dialog', { name: /buka kembali shift ini/i });
    await user.type(within(dialog).getByRole('textbox'), 'Koreksi kas');
    await user.click(within(dialog).getByRole('button', { name: 'Buka kembali shift' }));

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/masih memiliki shift lain/);
    expect(within(dialog).getByRole('button', { name: 'Buka kembali shift' })).toBeEnabled();
    expect(listSessions).toHaveBeenCalledTimes(1);
  });

  it('renders the history of an already reopened shift and marks it in the list', async () => {
    listSessions.mockResolvedValue({ data: [ROW(1, { status: 'open', closed_at: null, reopen_count: 2 })], meta: META });
    getSession.mockResolvedValue(REOPENED);
    await openDetail();

    // Drawer rincian juga memuat tabel (transaksi), jadi marker dicari di tabel daftar yang pertama.
    expect(within(screen.getAllByRole('table')[0]).getByText('Dibuka kembali')).toBeInTheDocument();
    expect(await screen.findByTestId('reopen-history')).toBeInTheDocument();
  });

  describe('close from history', () => {
    it('shows Close shift only when can_close, and closes with counted cash', async () => {
      closeSession.mockResolvedValue({});
      const opened = { ...REOPENED, can_close: true };
      getSession.mockReset();
      getSession.mockResolvedValueOnce(opened).mockResolvedValue(CLOSED());
      const user = await openDetail();

      await user.click(await screen.findByTestId('close-shift'));
      const dialog = await screen.findByTestId('close-dialog');
      const input = within(dialog).getByRole('spinbutton');
      await user.clear(input);
      await user.type(input, '150000');
      await user.click(within(dialog.parentElement.parentElement).getByRole('button', { name: /^tutup shift$/i }));

      await waitFor(() => expect(closeSession).toHaveBeenCalledWith(1, { closing_cash: '150000.00', notes: null }));
    });

    it('hides Close shift without can_close', async () => {
      await openDetail();
      expect(screen.queryByTestId('close-shift')).not.toBeInTheDocument();
    });
  });
});
