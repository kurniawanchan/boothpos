import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within, fireEvent, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SessionView from '../../resources/js/views/SessionView.vue';
import { currentSession, sessionSummary, closeSession } from '../../resources/js/api/sessions';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/sessions', () => ({
  currentSession: vi.fn(), sessionSummary: vi.fn(), closeSession: vi.fn(), openSession: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn().mockResolvedValue({ data: [] }) }));

const session = { id: 4, status: 'open', opened_at: '2026-10-03T08:00:00+07:00', opening_cash: '100000.00', event_id: 1 };
const summary = {
  order_count: 0, total_sales: '0.00', opening_cash: '100000.00', opening_cash_entries: [],
  by_method: [{ method: 'cash', count: 0, total: '0.00' }, { method: 'bank_transfer', count: 0, total: '0.00' }, { method: 'qr_ewallet', count: 0, total: '0.00' }],
};

async function renderSession() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: SessionView }, { path: '/floor/tabs/:id', name: 'floor-tab', component: { template: '<div />' } }],
  });
  router.push('/');
  await router.isReady();
  return render(SessionView, { global: { plugins: [pinia, router] } });
}


// Kas fisik sama dengan yang diharapkan (kas awal) → tidak ada selisih, tidak butuh catatan,
// jadi permintaan tutup benar-benar sampai ke server.
async function closeWithMatchingCash(user) {
  await fireEvent.update(await screen.findByLabelText(/kas fisik dihitung/i), '100000');
  await user.click(screen.getByRole('button', { name: /tutup sesi/i }));
}

describe('SessionView — closing a shift that still has open tabs (028)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(session);
    sessionSummary.mockResolvedValue(summary);
  });

  it('lists each blocking tab with a link to it when the server answers 409', async () => {
    closeSession.mockRejectedValue(new ApiError('Shift belum bisa ditutup', {
      status: 409,
      data: { open_tabs: [
        { id: 9, order_number: 'TRX-9', table: '12', tab_label: null },
        { id: 10, order_number: 'TRX-10', table: null, tab_label: 'Budi' },
      ] },
    }));
    const user = userEvent.setup();
    await renderSession();

    await closeWithMatchingCash(user);

    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText(/masih ada tab terbuka/i)).toBeInTheDocument();
    const tableTab = within(alert).getByRole('link', { name: /TRX-9 — Meja 12/ });
    expect(tableTab).toHaveAttribute('href', '/floor/tabs/9');
    expect(within(alert).getByRole('link', { name: /TRX-10 — Budi/ })).toHaveAttribute('href', '/floor/tabs/10');
  });

  it('shows no such list for an ordinary conflict', async () => {
    closeSession.mockRejectedValue(new ApiError('Sesi sudah ditutup', { status: 409, data: {} }));
    const user = userEvent.setup();
    await renderSession();

    await closeWithMatchingCash(user);

    expect(screen.queryByText(/masih ada tab terbuka/i)).toBeNull();
  });
});


// 042-dashboard-revamp (T032) — tujuan tautan "Selisih kas shift": /session?id=<shift> menampilkan rincian shift
// (tutup) itu hanya-baca di atas layar; tanpa ?id layar tidak berubah.
describe('SessionView — ?id= shift detail (Dashboard link, 042)', () => {
  const CLOSED = {
    session: { id: 17, status: 'closed', opening_cash: '100000.00', expected_cash: '250000.00', closing_cash: '226500.00', cash_difference: '-23500.00', closed_at: '2026-10-11T20:00:00+07:00', notes: 'Uang kembalian kurang' },
    order_count: 12, total_sales: '900000.00', by_method: [], opening_cash_entries: [],
  };

  async function renderAt(url) {
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/session', name: 'session', component: SessionView }, { path: '/floor/tabs/:id', name: 'floor-tab', component: { template: '<div />' } }] });
    router.push(url);
    await router.isReady();
    return { router, ...render(SessionView, { global: { plugins: [pinia, router] } }) };
  }

  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null); // tidak ada shift berjalan
    sessionSummary.mockResolvedValue(CLOSED);
  });

  it('loads the requested shift and shows opening, expected, counted cash and the signed difference', async () => {
    await renderAt('/session?id=17');
    const card = await screen.findByTestId('focused-shift');
    expect(await within(card).findByText('Rp 226.500')).toBeInTheDocument();
    expect(sessionSummary).toHaveBeenCalledWith(17);
    expect(within(card).getByRole('heading', { name: 'Shift #17' })).toBeInTheDocument();
    expect(within(card).getByText('Rp 250.000')).toBeInTheDocument();
    expect(within(card).getByTestId('focused-shift-difference')).toHaveTextContent('−Rp 23.500');
    expect(within(card).getByText('Uang kembalian kurang')).toBeInTheDocument();
  });

  it('can be dismissed, which removes ?id from the address', async () => {
    const user = userEvent.setup();
    const { router } = await renderAt('/session?id=17');
    await screen.findByTestId('focused-shift');
    await user.click(screen.getByTestId('focused-shift-dismiss'));
    await waitFor(() => expect(router.currentRoute.value.query.id).toBeUndefined());
    expect(screen.queryByTestId('focused-shift')).toBeNull();
  });

  it('shows an error line (not a crash) when the shift cannot be read, e.g. another cashier\'s shift', async () => {
    sessionSummary.mockRejectedValue(new ApiError('forbidden', { status: 403 }));
    await renderAt('/session?id=17');
    const card = await screen.findByTestId('focused-shift');
    expect(await within(card).findByRole('alert')).toHaveTextContent('Rincian shift tidak dapat ditampilkan.');
  });

  it('does not add a card for the shift that is currently open (the main screen already shows it)', async () => {
    currentSession.mockResolvedValue({ ...session, id: 17 });
    sessionSummary.mockResolvedValue({ ...summary, session: { id: 17 } });
    await renderAt('/session?id=17');
    await screen.findByText(/sesi #17|shift #17|#17/i);
    expect(screen.queryByTestId('focused-shift')).toBeNull();
  });

  it('is unchanged without ?id: no card and no extra summary request', async () => {
    currentSession.mockResolvedValue(null);
    await renderAt('/session');
    await screen.findAllByText(/buka sesi/i);
    expect(screen.queryByTestId('focused-shift')).toBeNull();
    expect(sessionSummary).not.toHaveBeenCalled();
  });
});
