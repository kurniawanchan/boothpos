import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SessionView from '../../resources/js/views/SessionView.vue';
import { currentSession, openSession } from '../../resources/js/api/sessions';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 045 Stage 2 (T043) — shift baru menyebut outletnya bila pengguna punya lebih dari satu; di satu outlet
// kolomnya tidak ada dan body tidak membawa outlet_id (server memakai satu-satunya outlet).
vi.mock('../../resources/js/api/sessions', () => ({
  currentSession: vi.fn(), sessionSummary: vi.fn(), closeSession: vi.fn(), openSession: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn().mockResolvedValue({ data: [] }) }));

const TWO = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];

async function renderOpenForm({ outlets, selected = null }) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const store = useOutletStore();
  store.outlets = outlets;
  store.multi = outlets.length > 1;
  store.selected = selected;
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: SessionView }] });
  router.push('/');
  await router.isReady();
  return render(SessionView, { global: { plugins: [pinia, router] } });
}

describe('SessionView open-shift form (045 Stage 2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue(null);
    openSession.mockResolvedValue({ id: 9 });
  });

  it('asks for the outlet when the user has several and sends the chosen one', async () => {
    const user = userEvent.setup();
    await renderOpenForm({ outlets: TWO });

    await user.click(await screen.findByLabelText(/^outlet/i));
    await user.click(await screen.findByRole('option', { name: 'Warkop WJM 2' }));
    await user.click(screen.getByRole('button', { name: /buka sesi/i }));

    await waitFor(() => expect(openSession).toHaveBeenCalled());
    expect(openSession.mock.calls[0][0].outlet_id).toBe(2);
  });

  it('defaults to the outlet already chosen in the top bar', async () => {
    const user = userEvent.setup();
    await renderOpenForm({ outlets: TWO, selected: 1 });

    await screen.findByLabelText(/^outlet/i);
    await user.click(screen.getByRole('button', { name: /buka sesi/i }));

    await waitFor(() => expect(openSession).toHaveBeenCalled());
    expect(openSession.mock.calls[0][0].outlet_id).toBe(1);
  });

  it('hides the field at a single outlet and sends no outlet_id', async () => {
    const user = userEvent.setup();
    await renderOpenForm({ outlets: [TWO[0]] });

    await screen.findByRole('button', { name: /buka sesi/i });
    expect(screen.queryByLabelText(/^outlet/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /buka sesi/i }));

    await waitFor(() => expect(openSession).toHaveBeenCalled());
    expect(openSession.mock.calls[0][0]).not.toHaveProperty('outlet_id');
  });

  it('names the shift outlet in the open-shift header only when there are several outlets', async () => {
    const { sessionSummary } = await import('../../resources/js/api/sessions');
    sessionSummary.mockResolvedValue({ session: { id: 9 }, order_count: 0, total_sales: 0, by_method: [], opening_cash_entries: [] });
    currentSession.mockResolvedValue({ id: 9, status: 'open', user_name: 'Kasir', event_name: 'Event Aktif', opened_at: '2026-10-12T01:00:00Z', opening_cash: '0.00', outlet: { id: 2, code: 'WJ2', name: 'Warkop WJM 2' } });
    await renderOpenForm({ outlets: TWO });

    expect(await screen.findByTestId('session-outlet')).toHaveTextContent('Warkop WJM 2');
  });
});
