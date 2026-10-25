import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import DiningSetupView from '../../resources/js/views/DiningSetupView.vue';
import { listDiningTables } from '../../resources/js/api/diningTables';
import { listStations } from '../../resources/js/api/stations';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 071 (US1) — filter Outlet di Restaurant Setup: dibagi antara tab Meja dan Stasiun, terisi dari bilah atas dan mengikuti
// saat berganti, boleh dilepas ke "Semua outlet", dan daftar diminta TANPA header outlet bilah atas.
vi.mock('../../resources/js/api/diningTables', () => ({ listDiningTables: vi.fn(), createDiningTable: vi.fn(), updateDiningTable: vi.fn(), deleteDiningTable: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn(), createStation: vi.fn(), updateStation: vi.fn(), deleteStation: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [] }) }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];
const SKIP = { skipOutletHeader: true };
const lastTables = () => listDiningTables.mock.calls.at(-1);
const lastStations = () => listStations.mock.calls.at(-1);

function renderView({ outlets = OUTLETS, selected = null } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useOutletStore(), { outlets, selected, multi: outlets.length > 1 });
  return render(DiningSetupView, { global: { plugins: [pinia] } });
}

const filterButton = () => within(screen.getByTestId('outlet-filter')).getByRole('button');

describe('Restaurant Setup outlet filter (071 US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear(); // select() do store menyimpan pilihan bilah atas; jangan bocor antar tes
    listDiningTables.mockResolvedValue({ data: [] });
    listStations.mockResolvedValue({ data: [] });
  });

  it('shows the filter only at several outlets and only on the Tables and Stations tabs', async () => {
    const user = userEvent.setup();
    const { unmount } = renderView();
    expect(await screen.findByTestId('outlet-filter')).toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: 'Stasiun' }));
    expect(screen.getByTestId('outlet-filter')).toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: 'Modifier' }));
    expect(screen.queryByTestId('outlet-filter')).not.toBeInTheDocument();
    unmount();

    renderView({ outlets: [OUTLETS[0]] });
    await waitFor(() => expect(listDiningTables).toHaveBeenCalled());
    expect(screen.queryByTestId('outlet-filter')).not.toBeInTheDocument();
  });

  it('asks for every outlet without the header by default, and for the chosen outlet after picking one', async () => {
    const user = userEvent.setup();
    renderView();
    await waitFor(() => expect(listDiningTables).toHaveBeenCalled());
    expect(listDiningTables.mock.calls[0]).toEqual([{}, SKIP]);

    await user.click(filterButton());
    await user.click(await screen.findByRole('option', { name: 'Warkop WJM 2' }));

    await waitFor(() => expect(lastTables()).toEqual([{ outlet_id: [2] }, SKIP]));
  });

  it('sends several outlets when several are picked', async () => {
    const user = userEvent.setup();
    renderView();
    await waitFor(() => expect(listDiningTables).toHaveBeenCalled());

    await user.click(filterButton());
    await user.click(await screen.findByRole('option', { name: 'Warkop WJM 1' }));
    await user.click(await screen.findByRole('option', { name: 'Warkop WJM 2' }));

    await waitFor(() => expect(lastTables()).toEqual([{ outlet_id: [1, 2] }, SKIP]));
  });

  it('shares the choice with the Stations tab', async () => {
    const user = userEvent.setup();
    renderView();
    await waitFor(() => expect(listDiningTables).toHaveBeenCalled());
    await user.click(filterButton());
    await user.click(await screen.findByRole('option', { name: 'Warkop WJM 2' }));
    await waitFor(() => expect(lastTables()[0]).toEqual({ outlet_id: [2] }));

    await user.click(screen.getByRole('tab', { name: 'Stasiun' }));

    await waitFor(() => expect(listStations).toHaveBeenCalled());
    expect(lastStations()).toEqual([{ outlet_id: [2] }, SKIP]);
  });

  it('starts from the top-bar outlet and follows it when it changes', async () => {
    renderView({ selected: 2 });
    await waitFor(() => expect(listDiningTables).toHaveBeenCalled());
    expect(listDiningTables.mock.calls[0]).toEqual([{ outlet_id: [2] }, SKIP]);

    useOutletStore().select(1);

    await waitFor(() => expect(lastTables()).toEqual([{ outlet_id: [1] }, SKIP]));
  });

  it('keeps an explicit "all outlets" while the top bar has an outlet', async () => {
    const user = userEvent.setup();
    renderView({ selected: 2 });
    await waitFor(() => expect(listDiningTables).toHaveBeenCalled());

    await user.click(filterButton());
    await user.click(await screen.findByRole('option', { name: 'Semua outlet' }));

    await waitFor(() => expect(lastTables()).toEqual([{}, SKIP]));
  });

  it('shows a filtered empty message and a reset that returns to the starting point', async () => {
    const user = userEvent.setup();
    renderView({ selected: 2 });
    await waitFor(() => expect(listDiningTables).toHaveBeenCalled());
    expect(await screen.findByText('Tidak ada meja di outlet terpilih.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Atur ulang filter' })).not.toBeInTheDocument();

    await user.click(filterButton());
    await user.click(await screen.findByRole('option', { name: 'Semua outlet' }));
    await waitFor(() => expect(lastTables()[0]).toEqual({}));
    await user.click(await screen.findByRole('button', { name: 'Atur ulang filter' }));

    await waitFor(() => expect(lastTables()[0]).toEqual({ outlet_id: [2] }));
  });
});
