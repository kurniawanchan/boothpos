import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TablesAdmin from '../../resources/js/components/restaurant/TablesAdmin.vue';
import StationsAdmin from '../../resources/js/components/restaurant/StationsAdmin.vue';
import { listDiningTables, updateDiningTable } from '../../resources/js/api/diningTables';
import { listStations, updateStation } from '../../resources/js/api/stations';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { ApiError } from '../../resources/js/utils/errors';

// 071 (US2) — kolom Outlet di formulir UBAH Meja dan Stasiun: terisi outlet saat ini, mengirim `outlet_id` hanya bila
// diganti (pemindahan), tidak tampil di satu outlet; formulir BUAT tidak berubah.
vi.mock('../../resources/js/api/diningTables', () => ({ listDiningTables: vi.fn(), createDiningTable: vi.fn(), updateDiningTable: vi.fn(), deleteDiningTable: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn(), createStation: vi.fn(), updateStation: vi.fn(), deleteStation: vi.fn() }));

const TWO = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];
const TABLE = { id: 7, name: 'D1', area: 'Dalam', seats: 4, display_order: 1, is_active: true, outlet_id: 1, has_open_tab: false };
const STATION = { id: 3, name: 'Dapur', display_order: 0, is_active: true, outlet_id: 1 };

function renderWith(component, outlets = TWO) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useOutletStore(), { outlets, selected: null, multi: outlets.length > 1 });
  return render(component, { global: { plugins: [pinia] } });
}

const outletBox = () => screen.getByRole('combobox', { name: /outlet/i });
async function chooseOutlet(user, name) {
  await user.click(outletBox());
  await user.click(await screen.findByRole('option', { name }));
}

describe('Edit table outlet field (071 US2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDiningTables.mockResolvedValue({ data: [TABLE] });
    updateDiningTable.mockResolvedValue({ ...TABLE });
  });

  async function openEdit(user) {
    await screen.findByText('D1');
    await user.click(screen.getByRole('button', { name: 'Edit' }));
    await screen.findByLabelText(/nama \/ nomor meja/i);
  }

  it('shows the current outlet and sends outlet_id when it is changed', async () => {
    const user = userEvent.setup();
    renderWith(TablesAdmin);
    await openEdit(user);
    expect(outletBox()).toHaveTextContent('Warkop WJM 1');

    await chooseOutlet(user, 'Warkop WJM 2');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateDiningTable).toHaveBeenCalled());
    expect(updateDiningTable.mock.calls[0][0]).toBe(7);
    expect(updateDiningTable.mock.calls[0][1].outlet_id).toBe(2);
  });

  it('sends no outlet_id when only another field changes', async () => {
    const user = userEvent.setup();
    renderWith(TablesAdmin);
    await openEdit(user);

    await user.clear(screen.getByLabelText(/kursi|seats/i));
    await user.type(screen.getByLabelText(/kursi|seats/i), '6');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateDiningTable).toHaveBeenCalled());
    expect(updateDiningTable.mock.calls[0][1]).not.toHaveProperty('outlet_id');
  });

  it('has no Outlet field at a single outlet', async () => {
    const user = userEvent.setup();
    renderWith(TablesAdmin, [TWO[0]]);
    await openEdit(user);

    expect(screen.queryByRole('combobox', { name: /outlet/i })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Simpan' }));
    await waitFor(() => expect(updateDiningTable).toHaveBeenCalled());
    expect(updateDiningTable.mock.calls[0][1]).not.toHaveProperty('outlet_id');
  });

  it('shows a name clash at the target outlet on the name field and keeps the modal open', async () => {
    updateDiningTable.mockRejectedValue(new ApiError('x', { status: 422, errors: { name: ['Meja dengan nama ini sudah ada.'] } }));
    const user = userEvent.setup();
    renderWith(TablesAdmin);
    await openEdit(user);

    await chooseOutlet(user, 'Warkop WJM 2');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Meja dengan nama ini sudah ada.')).toBeInTheDocument();
    expect(screen.getByLabelText(/nama \/ nomor meja/i)).toBeInTheDocument();
  });

  it('keeps the modal open when the move is refused (409 is toasted globally)', async () => {
    updateDiningTable.mockRejectedValue(new ApiError('Meja ini masih punya tab yang terbuka.', { status: 409 }));
    const user = userEvent.setup();
    renderWith(TablesAdmin);
    await openEdit(user);

    await chooseOutlet(user, 'Warkop WJM 2');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateDiningTable).toHaveBeenCalled());
    expect(screen.getByLabelText(/nama \/ nomor meja/i)).toBeInTheDocument();
    expect(listDiningTables).toHaveBeenCalledTimes(1);
  });

  it('keeps the create form as before: outlet only when creating', async () => {
    const user = userEvent.setup();
    renderWith(TablesAdmin);
    await screen.findByText('D1');

    await user.click(screen.getByRole('button', { name: /meja baru/i }));
    expect(await screen.findByRole('combobox', { name: /outlet/i })).toBeInTheDocument();
  });
});

describe('Edit station outlet field (071 US2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [STATION] });
    updateStation.mockResolvedValue({ ...STATION });
  });

  async function openEdit(user) {
    await screen.findByText('Dapur');
    await user.click(screen.getByRole('button', { name: 'Edit' }));
    await screen.findByLabelText(/nama stasiun/i);
  }

  it('shows the current outlet and sends outlet_id when it is changed', async () => {
    const user = userEvent.setup();
    renderWith(StationsAdmin);
    await openEdit(user);
    expect(outletBox()).toHaveTextContent('Warkop WJM 1');

    await chooseOutlet(user, 'Warkop WJM 2');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateStation).toHaveBeenCalled());
    expect(updateStation.mock.calls[0][0]).toBe(3);
    expect(updateStation.mock.calls[0][1].outlet_id).toBe(2);
  });

  it('sends no outlet_id when the outlet is untouched', async () => {
    const user = userEvent.setup();
    renderWith(StationsAdmin);
    await openEdit(user);

    await user.type(screen.getByLabelText(/nama stasiun/i), ' 2');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateStation).toHaveBeenCalled());
    expect(updateStation.mock.calls[0][1]).not.toHaveProperty('outlet_id');
  });

  it('has no Outlet field at a single outlet, and shows server errors on the name', async () => {
    const { unmount } = renderWith(StationsAdmin, [TWO[0]]);
    const user = userEvent.setup();
    await openEdit(user);
    expect(screen.queryByRole('combobox', { name: /outlet/i })).not.toBeInTheDocument();
    unmount();

    updateStation.mockRejectedValue(new ApiError('x', { status: 422, errors: { name: ['Stasiun dengan nama ini sudah ada.'] } }));
    renderWith(StationsAdmin);
    await openEdit(user);
    await chooseOutlet(user, 'Warkop WJM 2');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Stasiun dengan nama ini sudah ada.')).toBeInTheDocument();
    expect(within(screen.getByRole('dialog')).getByLabelText(/nama stasiun/i)).toBeInTheDocument();
  });
});
