import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import FloorView from '../../resources/js/views/FloorView.vue';
import { getFloor } from '../../resources/js/api/floor';
import { currentSession } from '../../resources/js/api/sessions';
import { openTab } from '../../resources/js/api/tabs';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/sessions', () => ({ currentSession: vi.fn() }));
vi.mock('../../resources/js/api/tabs', () => ({ openTab: vi.fn(), getTab: vi.fn() }));

const table = (over) => ({ id: 1, name: '1', seats: 4, is_active: true, state: 'free', tab: null, ...over });

async function renderFloor() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'floor', component: FloorView },
      { path: '/floor/tabs/:id', name: 'floor-tab', component: { template: '<div />' } },
      { path: '/session', name: 'session', component: { template: '<div />' } },
    ],
  });
  router.push('/');
  await router.isReady();
  return { router, ...render(FloorView, { global: { plugins: [pinia, router] } }) };
}

describe('FloorView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    currentSession.mockResolvedValue({ id: 3, status: 'open' });
  });

  it('shows tables grouped by area with free and occupied states', async () => {
    getFloor.mockResolvedValue({
      areas: [
        { area: 'Indoor', tables: [table({ id: 1, name: '1' }), table({
          id: 2, name: '2', state: 'occupied',
          tab: { id: 9, order_number: 'TRX-1', total_amount: '125000.00', opened_at: '2026-10-03T10:00:00+07:00', session_id: 1, ready_count: 2, unsent_count: 1 },
        })] },
        { area: '', tables: [table({ id: 3, name: 'Bar' })] },
      ],
      takeaway_tabs: [],
      version: 'v1',
    });
    await renderFloor();

    const indoor = await screen.findByRole('region', { name: 'Indoor' });
    expect(within(indoor).getByTestId('table-1')).toHaveAttribute('data-state', 'free');
    const busy = within(indoor).getByTestId('table-2');
    expect(busy).toHaveAttribute('data-state', 'occupied');
    expect(within(busy).getByText('TRX-1')).toBeInTheDocument();
    expect(within(busy).getByText(/125\.000/)).toBeInTheDocument();
    expect(within(busy).getByText('2 siap saji')).toBeInTheDocument();
    expect(within(busy).getByText('1 belum dikirim')).toBeInTheDocument();

    // Meja tanpa area diberi label eksplisit, bukan judul kosong.
    expect(screen.getByRole('region', { name: 'Tanpa area' })).toBeInTheDocument();
  });

  it('lists takeaway tabs separately', async () => {
    getFloor.mockResolvedValue({
      areas: [],
      takeaway_tabs: [{ id: 5, order_number: 'TRX-5', tab_label: 'Budi', total_amount: '45000.00', ready_count: 0, unsent_count: 2 }],
      version: 'v1',
    });
    await renderFloor();

    const section = await screen.findByRole('region', { name: 'Takeaway' });
    expect(within(section).getByText('Budi')).toBeInTheDocument();
  });

  it('shows the empty state when there are no tables at all', async () => {
    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v0' });
    await renderFloor();

    expect(await screen.findByText(/belum ada meja/i)).toBeInTheDocument();
  });

  it('warns, without crashing, when the floor cannot be loaded', async () => {
    getFloor.mockRejectedValue(new Error('offline'));
    await renderFloor();

    expect(await screen.findByRole('alert')).toHaveTextContent(/tidak dapat memuat lantai/i);
  });

  const busyTab = { id: 9, order_number: 'TRX-9', total_amount: '50000.00', opened_at: '2026-10-03T10:00:00+07:00', session_id: 3, ready_count: 0, unsent_count: 0 };
  const twoTables = () => ({
    areas: [{ area: 'Indoor', tables: [table({ id: 1, name: '1' }), table({ id: 2, name: '2', state: 'occupied', tab: busyTab })] }],
    takeaway_tabs: [], version: 'v1',
  });

  it('opens an occupied table\'s tab when its tile is clicked', async () => {
    getFloor.mockResolvedValue(twoTables());
    const user = userEvent.setup();
    const { router } = await renderFloor();

    await user.click(await screen.findByTestId('table-2'));

    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/floor/tabs/9'));
    expect(openTab).not.toHaveBeenCalled();
  });

  it('opens a new tab on a free table after confirmation, with this shift and an idempotency ref', async () => {
    getFloor.mockResolvedValue(twoTables());
    openTab.mockResolvedValue({ id: 31 });
    const user = userEvent.setup();
    const { router } = await renderFloor();
    await screen.findByTestId('table-1');
    await waitFor(() => expect(currentSession).toHaveBeenCalled());

    await user.click(screen.getByTestId('table-1'));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/buka tab untuk meja 1/i)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Buka tab' }));

    await waitFor(() => expect(openTab).toHaveBeenCalledTimes(1));
    const body = openTab.mock.calls[0][0];
    expect(body).toMatchObject({ session_id: 3, table_id: 1 });
    expect(body.local_ref).toMatch(/^[0-9a-f-]{36}$/);
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/floor/tabs/31'));
  });

  it('sends the guest to the existing tab when someone else just opened the table (409)', async () => {
    getFloor.mockResolvedValue(twoTables());
    openTab.mockRejectedValue(new ApiError('Meja sudah dipakai', { status: 409, data: { existing_tab_id: 77 } }));
    const user = userEvent.setup();
    const { router } = await renderFloor();
    await screen.findByTestId('table-1');
    await waitFor(() => expect(currentSession).toHaveBeenCalled());

    await user.click(screen.getByTestId('table-1'));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Buka tab' }));

    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/floor/tabs/77'));
  });

  it('explains that a shift is needed, and does not open a tab, when no shift is open', async () => {
    currentSession.mockResolvedValue(null);
    getFloor.mockResolvedValue(twoTables());
    const user = userEvent.setup();
    await renderFloor();

    expect(await screen.findByRole('status')).toHaveTextContent(/buka sesi kasir dulu/i);
    await user.click(await screen.findByTestId('table-1'));

    expect(screen.queryByRole('dialog')).toBeNull();
    expect(openTab).not.toHaveBeenCalled();
  });

  it('opens a takeaway tab with an optional label', async () => {
    getFloor.mockResolvedValue(twoTables());
    openTab.mockResolvedValue({ id: 55 });
    const user = userEvent.setup();
    const { router } = await renderFloor();
    await screen.findByTestId('table-1');
    await waitFor(() => expect(currentSession).toHaveBeenCalled());

    await user.click(screen.getByRole('button', { name: 'Tab takeaway' }));
    await user.type(await screen.findByLabelText(/nama \/ label/i), 'Budi');
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Buka tab' }));

    await waitFor(() => expect(openTab).toHaveBeenCalledTimes(1));
    expect(openTab.mock.calls[0][0]).toMatchObject({ session_id: 3, tab_label: 'Budi' });
    expect(openTab.mock.calls[0][0].table_id).toBeUndefined();
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/floor/tabs/55'));
  });
});
