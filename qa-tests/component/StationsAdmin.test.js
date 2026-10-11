import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import StationsAdmin from '../../resources/js/components/restaurant/StationsAdmin.vue';
import { listStations, createStation, deleteStation } from '../../resources/js/api/stations';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn(), createStation: vi.fn(), updateStation: vi.fn(), deleteStation: vi.fn() }));

const station = (over) => ({ id: 1, name: 'Dapur', display_order: 0, is_active: true, ...over });

function renderAdmin() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(StationsAdmin, { global: { plugins: [pinia] } });
}

describe('StationsAdmin', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [station(), station({ id: 2, name: 'Bar', display_order: 1, is_active: false })] });
  });

  it('lists stations with their status', async () => {
    renderAdmin();

    expect(await screen.findByText('Dapur')).toBeInTheDocument();
    expect(screen.getByText('Bar')).toBeInTheDocument();
    expect(screen.getByText('Nonaktif')).toBeInTheDocument();
  });

  it('creates a station and reloads the list', async () => {
    createStation.mockResolvedValue(station({ id: 3, name: 'Pastry' }));
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Dapur');

    await user.click(screen.getByRole('button', { name: 'Stasiun baru' }));
    await user.type(await screen.findByLabelText(/nama stasiun/i), 'Pastry');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(createStation).toHaveBeenCalledTimes(1));
    expect(createStation.mock.calls[0][0]).toMatchObject({ name: 'Pastry', is_active: true });
    await waitFor(() => expect(listStations).toHaveBeenCalledTimes(2));
  });

  it('shows the server message next to the field when the name is taken', async () => {
    createStation.mockRejectedValue(new ApiError('x', { status: 422, errors: { name: ['Stasiun dengan nama ini sudah ada.'] } }));
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Dapur');

    await user.click(screen.getByRole('button', { name: 'Stasiun baru' }));
    await user.type(await screen.findByLabelText(/nama stasiun/i), 'Dapur');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Stasiun dengan nama ini sudah ada.')).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('deletes a station after confirmation', async () => {
    deleteStation.mockResolvedValue();
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Dapur');

    await user.click(screen.getAllByRole('button', { name: 'Hapus' })[1]);
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/hapus stasiun bar/i)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deleteStation).toHaveBeenCalledWith(2));
  });
});
