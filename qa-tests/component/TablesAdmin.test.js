import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TablesAdmin from '../../resources/js/components/restaurant/TablesAdmin.vue';
import { listDiningTables, createDiningTable, deleteDiningTable } from '../../resources/js/api/diningTables';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/diningTables', () => ({
  listDiningTables: vi.fn(),
  createDiningTable: vi.fn(),
  updateDiningTable: vi.fn(),
  deleteDiningTable: vi.fn(),
}));

const row = (over) => ({ id: 1, name: '7', area: 'Indoor', seats: 4, display_order: 0, is_active: true, has_open_tab: false, ...over });

function renderAdmin() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(TablesAdmin, { global: { plugins: [pinia] } });
}

describe('TablesAdmin', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDiningTables.mockResolvedValue({ data: [row(), row({ id: 2, name: 'Bar', area: '', is_active: false }), row({ id: 3, name: '9', has_open_tab: true })] });
  });

  it('lists tables with their status and open-tab marker', async () => {
    renderAdmin();

    expect(await screen.findByText('Bar')).toBeInTheDocument();
    expect(screen.getByText('Nonaktif')).toBeInTheDocument();
    expect(screen.getByText('Ada tab terbuka')).toBeInTheDocument();
  });

  it('creates a table from the form and reloads the list', async () => {
    createDiningTable.mockResolvedValue(row({ id: 4, name: 'T1' }));
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Bar');

    await user.click(screen.getByRole('button', { name: /meja baru/i }));
    await user.type(await screen.findByLabelText(/nama \/ nomor meja/i), 'T1');
    await user.type(screen.getByLabelText(/^area/i), 'Terrace');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(createDiningTable).toHaveBeenCalledTimes(1));
    expect(createDiningTable.mock.calls[0][0]).toMatchObject({ name: 'T1', area: 'Terrace', is_active: true });
    await waitFor(() => expect(listDiningTables).toHaveBeenCalledTimes(2));
  });

  it('shows the server message next to the field when the name is already taken (422)', async () => {
    createDiningTable.mockRejectedValue(new ApiError('Invalid', { status: 422, errors: { name: ['Meja dengan nama ini sudah ada di area tersebut.'] } }));
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Bar');

    await user.click(screen.getByRole('button', { name: /meja baru/i }));
    await user.type(await screen.findByLabelText(/nama \/ nomor meja/i), '7');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText(/sudah ada di area tersebut/i)).toBeInTheDocument();
  });

  it('deletes a table after confirmation', async () => {
    deleteDiningTable.mockResolvedValue();
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Bar');

    await user.click(screen.getAllByRole('button', { name: 'Hapus' })[1]); // baris "Bar"
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/hapus meja bar/i)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deleteDiningTable).toHaveBeenCalledWith(2));
  });
});
