import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createRouter, createMemoryHistory } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import ActivityLogView from '../../resources/js/views/ActivityLogView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listActivityLogs } from '../../resources/js/api/activityLog';

vi.mock('../../resources/js/api/activityLog', () => ({ listActivityLogs: vi.fn() }));

function renderActivityLog() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'reports'] };
  return render(ActivityLogView, { global: { plugins: [pinia] } });
}

describe('ActivityLogView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listActivityLogs.mockResolvedValue({
      data: [
        {
          id: 1,
          created_at: '2026-09-01T10:00:00Z',
          user_name: 'Owner',
          action: 'purchase_order.received',
          entity_type: 'PurchaseOrder',
          entity_id: 5,
          description: 'Menerima PO-2026-0001',
        },
      ],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('lists activity log entries from the API', async () => {
    renderActivityLog();
    expect(await screen.findByText('purchase_order.received')).toBeInTheDocument();
    expect(screen.getByText('Menerima PO-2026-0001')).toBeInTheDocument();
    expect(listActivityLogs).toHaveBeenCalled();
  });

  it('re-fetches with the filter params when a filter changes', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderActivityLog();
    await screen.findByText('purchase_order.received');

    const actionInput = screen.getByLabelText(/^aksi$/i);
    await user.type(actionInput, 'purchase_order.received');
    actionInput.blur();

    await screen.findByText('purchase_order.received');
    expect(listActivityLogs).toHaveBeenLastCalledWith(
      expect.objectContaining({ action: 'purchase_order.received', page: 1 })
    );
  }, 20000);
});


// 042-dashboard-revamp (T032) — tujuan tautan "Void setelah fire": /activity-log?action=item_cancelled&date=YYYY-MM-DD.
describe('ActivityLogView — filters from the address (Dashboard link, 042)', () => {
  async function renderAt(url) {
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'reports'] };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/activity-log', name: 'activity-log', component: { template: '<div />' } }] });
    router.push(url);
    await router.isReady();
    return render(ActivityLogView, { global: { plugins: [pinia, router] } });
  }

  beforeEach(() => {
    vi.clearAllMocks();
    listActivityLogs.mockResolvedValue({
      data: [{ id: 9, created_at: '2026-10-11T10:00:00Z', user_name: 'Kasir', action: 'item_cancelled', entity_type: 'KitchenTicketItem', entity_id: 3, description: 'Item dibatalkan' }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('sends action and date in the FIRST request, shows the action in the box and the date as a chip', async () => {
    await renderAt('/activity-log?action=item_cancelled&date=2026-10-11');
    expect(await screen.findByText('Item dibatalkan')).toBeInTheDocument();
    expect(listActivityLogs).toHaveBeenCalledTimes(1);
    expect(listActivityLogs).toHaveBeenCalledWith(expect.objectContaining({ action: 'item_cancelled', date: '2026-10-11', page: 1 }));
    expect(screen.getByLabelText(/^aksi$/i)).toHaveValue('item_cancelled');
    expect(screen.getByTestId('log-date-chip')).toHaveTextContent('Tanggal bisnis: 2026-10-11');
    expect(screen.getByTestId('data-count')).toHaveTextContent('1 data');
  });

  it('removing the date chip re-fetches without `date` and keeps the action filter', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderAt('/activity-log?action=item_cancelled&date=2026-10-11');
    await screen.findByText('Item dibatalkan');

    await user.click(screen.getByTestId('log-date-chip'));
    await waitFor(() => expect(listActivityLogs.mock.lastCall[0].date).toBeUndefined());
    expect(listActivityLogs.mock.lastCall[0]).toMatchObject({ action: 'item_cancelled' });
    expect(screen.queryByTestId('log-date-chip')).toBeNull();
  });

  it('"Atur ulang filter" clears the date too', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderAt('/activity-log?action=item_cancelled&date=2026-10-11');
    await screen.findByText('Item dibatalkan');

    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listActivityLogs.mock.lastCall[0].date).toBeUndefined());
    expect(listActivityLogs.mock.lastCall[0].action).toBeUndefined();
  });

  it('ignores an invalid date and sends no filter when the address has none', async () => {
    await renderAt('/activity-log?date=kemarin');
    await screen.findByText('Item dibatalkan');
    expect(listActivityLogs.mock.calls[0][0]).not.toHaveProperty('date');
    expect(listActivityLogs.mock.calls[0][0]).not.toHaveProperty('action');
    expect(screen.queryByTestId('log-date-chip')).toBeNull();
  });
});
