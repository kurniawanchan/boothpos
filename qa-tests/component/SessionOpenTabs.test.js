import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SessionView from '../../resources/js/views/SessionView.vue';
import { currentSession, sessionSummary, closeSession } from '../../resources/js/api/sessions';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/sessions', () => ({
  currentSession: vi.fn(), sessionSummary: vi.fn(), closeSession: vi.fn(), openSession: vi.fn(),
}));
vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn().mockResolvedValue({ data: [] }) }));

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
