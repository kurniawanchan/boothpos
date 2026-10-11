import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ActivityLogView from '../../resources/js/views/ActivityLogView.vue';
import { listActivityLogs } from '../../resources/js/api/activityLog';

vi.mock('../../resources/js/api/activityLog', () => ({ listActivityLogs: vi.fn() }));

function renderIt() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(ActivityLogView, { global: { plugins: [pinia] } });
}

// 041 (US2) — Log Aktivitas: PageHeader, FilterBar dengan penghitung dari total server, keadaan galat.
describe('ActivityLogView — page kit structure (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows the page heading and a counter equal to the server total (not the page size)', async () => {
    listActivityLogs.mockResolvedValue({
      data: [{ id: 1, created_at: '2026-09-01T10:00:00Z', user_name: 'Owner', action: 'a.b', entity_type: 'X', entity_id: 1, description: 'd' }],
      meta: { current_page: 1, per_page: 25, total: 1284, last_page: 52 },
    });
    renderIt();
    expect(screen.getByRole('heading', { level: 1, name: 'Log Aktivitas' })).toBeInTheDocument();
    await screen.findByText('a.b');
    expect(screen.getByTestId('data-count')).toHaveTextContent('1.284 data');
  });

  it('offers reset only while a filter is set, and reset re-fetches without filters', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listActivityLogs.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
    renderIt();
    await screen.findByText(/belum ada aktivitas/i);
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();

    const input = screen.getByLabelText(/^aksi$/i);
    await user.type(input, 'x.y');
    input.blur();
    await user.click(await screen.findByRole('button', { name: /atur ulang filter/i }));
    expect(listActivityLogs).toHaveBeenLastCalledWith(expect.objectContaining({ action: undefined, page: 1 }));
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();
  });

  it('shows an error state with retry when loading fails', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listActivityLogs.mockRejectedValueOnce(new Error('boom')).mockResolvedValue({
      data: [{ id: 1, created_at: '2026-09-01T10:00:00Z', user_name: 'Owner', action: 'ok.row', entity_type: 'X', entity_id: null, description: 'd' }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
    renderIt();
    expect(await screen.findByRole('alert')).toHaveTextContent(/gagal memuat/i);
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('ok.row')).toBeInTheDocument();
  });
});
