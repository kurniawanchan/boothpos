import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import TablesAdmin from '../../resources/js/components/restaurant/TablesAdmin.vue';
import StationsAdmin from '../../resources/js/components/restaurant/StationsAdmin.vue';
import { listDiningTables } from '../../resources/js/api/diningTables';
import { listStations } from '../../resources/js/api/stations';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 071 (US1) — kolom Outlet pada tabel Meja dan Stasiun: kode tebal + nama dari daftar outlet pengguna; tidak ada di satu outlet.
vi.mock('../../resources/js/api/diningTables', () => ({ listDiningTables: vi.fn(), createDiningTable: vi.fn(), updateDiningTable: vi.fn(), deleteDiningTable: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn(), createStation: vi.fn(), updateStation: vi.fn(), deleteStation: vi.fn() }));

const TWO = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];

function renderWith(component, outlets, props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useOutletStore(), { outlets, selected: null, multi: outlets.length > 1 });
  return render(component, { props, global: { plugins: [pinia] } });
}

describe('Outlet column in Restaurant Setup tables (071 US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDiningTables.mockResolvedValue({ data: [
      { id: 1, name: 'D1', area: 'Dalam', seats: 4, display_order: 1, is_active: true, outlet_id: 1, has_open_tab: false },
      { id: 2, name: 'D1', area: 'Dalam', seats: 4, display_order: 1, is_active: true, outlet_id: 2, has_open_tab: false },
      { id: 3, name: 'X9', area: '', seats: 2, display_order: 2, is_active: true, outlet_id: 99, has_open_tab: false },
    ] });
    listStations.mockResolvedValue({ data: [
      { id: 1, name: 'Dapur', display_order: 0, is_active: true, outlet_id: 2 },
    ] });
  });

  it('shows each table outlet with its code and name, and a dash for an unknown outlet', async () => {
    renderWith(TablesAdmin, TWO);
    await screen.findAllByText('D1');

    expect(screen.getByRole('columnheader', { name: 'Outlet' })).toBeInTheDocument();
    const rows = screen.getAllByRole('row').slice(1);
    expect(within(rows[0]).getByText('WJ1')).toBeInTheDocument();
    expect(within(rows[0]).getByText('Warkop WJM 1')).toBeInTheDocument();
    expect(within(rows[1]).getByText('WJ2')).toBeInTheDocument();
    expect(within(rows[2]).getAllByText('—').length).toBeGreaterThan(0);
  });

  it('shows each station outlet', async () => {
    renderWith(StationsAdmin, TWO);
    await screen.findByText('Dapur');

    expect(screen.getByRole('columnheader', { name: 'Outlet' })).toBeInTheDocument();
    const row = screen.getAllByRole('row')[1];
    expect(within(row).getByText('WJ2')).toBeInTheDocument();
    expect(within(row).getByText('Warkop WJM 2')).toBeInTheDocument();
  });

  it('has no Outlet column at a single outlet', async () => {
    const { unmount } = renderWith(TablesAdmin, [TWO[0]]);
    await screen.findAllByText('D1');
    expect(screen.queryByRole('columnheader', { name: 'Outlet' })).not.toBeInTheDocument();
    unmount();

    renderWith(StationsAdmin, [TWO[0]]);
    await screen.findByText('Dapur');
    expect(screen.queryByRole('columnheader', { name: 'Outlet' })).not.toBeInTheDocument();
  });

  it('reloads with the outlet filter given by the page, without the top-bar header', async () => {
    const { rerender } = renderWith(TablesAdmin, TWO, { outletIds: [2] });
    await screen.findAllByText('D1');
    expect(listDiningTables).toHaveBeenLastCalledWith({ outlet_id: [2] }, { skipOutletHeader: true });

    await rerender({ outletIds: [] });

    await vi.waitFor(() => expect(listDiningTables).toHaveBeenLastCalledWith({}, { skipOutletHeader: true }));
  });
});
