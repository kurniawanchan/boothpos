import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
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
  });
});
