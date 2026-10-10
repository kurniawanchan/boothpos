import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import KitchenView from '../../resources/js/views/KitchenView.vue';
import { listStations } from '../../resources/js/api/stations';
import { getStationTickets, setTicketItemStatus, getTicket } from '../../resources/js/api/kitchen';
import { useAuthStore } from '../../resources/js/stores/auth';

vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn() }));
vi.mock('../../resources/js/api/kitchen', () => ({ getStationTickets: vi.fn(), setTicketItemStatus: vi.fn(), getTicket: vi.fn() }));

const item = (over) => ({ id: 1, name: 'Nasi Goreng', qty: 2, modifiers: [], note: null, status: 'new', cancel_reason: null, ...over });
const ticket = (over) => ({
  id: 10, round_no: 1, sent_at: new Date(Date.now() - 4 * 60000).toISOString(), station: { id: 1, name: 'Dapur' },
  order: { id: 3, order_number: 'TRX-3' }, table: { id: 7, name: '7' }, tab_label: null, waiting_seconds: 240, items: [item()], ...over,
});
const feed = (tickets, version = 'v1') => ({ tickets, version });

function renderKitchen(menuKeys = ['kitchen']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Koki', name: 'Koki', menu_keys: menuKeys };
  return render(KitchenView, { global: { plugins: [pinia] } });
}

describe('KitchenView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [{ id: 1, name: 'Dapur', is_active: true }, { id: 2, name: 'Bar', is_active: true }] });
    getStationTickets.mockResolvedValue(feed([ticket()]));
  });

  it('shows the first station\'s tickets with place, number, round, waiting time and items', async () => {
    getStationTickets.mockResolvedValue(feed([ticket({ round_no: 2, items: [item({ note: 'pedas', modifiers: [{ group: 'Level', option: 'Extra', price_delta: '0.00' }] })] })]));
    renderKitchen();

    const card = await screen.findByTestId('ticket-10');
    expect(within(card).getByRole('heading', { name: 'Meja 7' })).toBeInTheDocument();
    expect(within(card).getByText(/TRX-3 · Ronde 2/)).toBeInTheDocument();
    expect(within(card).getByText('4 mnt')).toBeInTheDocument();
    expect(within(card).getByText('Nasi Goreng')).toBeInTheDocument();
    expect(within(card).getByText('Extra')).toBeInTheDocument();
    expect(within(card).getByText('“pedas”')).toBeInTheDocument();
    expect(getStationTickets).toHaveBeenCalledWith(1, null);
  });

  it('labels a takeaway ticket with its tab label', async () => {
    getStationTickets.mockResolvedValue(feed([ticket({ table: null, tab_label: 'Budi' })]));
    renderKitchen();

    expect(await screen.findByRole('heading', { name: 'Budi' })).toBeInTheDocument();
  });

  it('switches station and shows that station\'s tickets only', async () => {
    getStationTickets.mockImplementation((id) => Promise.resolve(feed(id === 1 ? [ticket()] : [ticket({ id: 20, items: [item({ id: 9, name: 'Es Kopi' })] })])));
    const user = userEvent.setup();
    renderKitchen();
    await screen.findByText('Nasi Goreng');

    await user.click(screen.getByRole('tab', { name: 'Bar' }));

    expect(await screen.findByText('Es Kopi')).toBeInTheDocument();
    expect(screen.queryByText('Nasi Goreng')).toBeNull();
    expect(screen.getByRole('tab', { name: 'Bar' })).toHaveAttribute('aria-selected', 'true');
  });

  it('offers exactly the next step for each item status', async () => {
    getStationTickets.mockResolvedValue(feed([ticket({ items: [
      item({ id: 1, name: 'A', status: 'new' }), item({ id: 2, name: 'B', status: 'preparing' }),
      item({ id: 3, name: 'C', status: 'ready' }), item({ id: 4, name: 'D', status: 'served' }),
    ] })]));
    renderKitchen();

    await screen.findByTestId('ticket-item-1');
    expect(within(screen.getByTestId('ticket-item-1')).getByRole('button', { name: 'Mulai' })).toBeInTheDocument();
    expect(within(screen.getByTestId('ticket-item-2')).getByRole('button', { name: 'Siap' })).toBeInTheDocument();
    expect(within(screen.getByTestId('ticket-item-3')).getByRole('button', { name: 'Sudah diantar' })).toBeInTheDocument();
    expect(within(screen.getByTestId('ticket-item-4')).queryByRole('button')).toBeNull();
  });

  it('marks a cancelled item clearly, with its reason, and gives it no action', async () => {
    getStationTickets.mockResolvedValue(feed([ticket({ items: [item({ id: 5, name: 'Mie', status: 'cancelled', cancel_reason: 'stok habis' }), item({ id: 6, name: 'Nasi' })] })]));
    renderKitchen();

    const cancelled = await screen.findByTestId('ticket-item-5');
    expect(within(cancelled).getByText(/dibatalkan — stok habis/i)).toBeInTheDocument();
    expect(within(cancelled).queryByRole('button')).toBeNull();
  });

  it('sends the status change and reloads the board in full', async () => {
    setTicketItemStatus.mockResolvedValue({ status: 'preparing' });
    const user = userEvent.setup();
    renderKitchen();
    await screen.findByTestId('ticket-item-1');
    getStationTickets.mockClear();
    getStationTickets.mockResolvedValue(feed([ticket({ items: [item({ status: 'preparing' })] })], 'v2'));

    await user.click(within(screen.getByTestId('ticket-item-1')).getByRole('button', { name: 'Mulai' }));

    expect(setTicketItemStatus).toHaveBeenCalledWith(1, 'preparing');
    await waitFor(() => expect(getStationTickets).toHaveBeenCalledWith(1, null)); // pengambilan penuh
    expect(await within(screen.getByTestId('ticket-item-1')).findByRole('button', { name: 'Siap' })).toBeInTheDocument();
  });

  it('gives a waiter (floor only) the serve action but not the cooking actions', async () => {
    getStationTickets.mockResolvedValue(feed([ticket({ items: [item({ id: 1, status: 'new' }), item({ id: 3, status: 'ready' })] })]));
    renderKitchen(['floor']);

    await screen.findByTestId('ticket-item-1');
    expect(within(screen.getByTestId('ticket-item-1')).queryByRole('button')).toBeNull();
    expect(within(screen.getByTestId('ticket-item-3')).getByRole('button', { name: 'Sudah diantar' })).toBeInTheDocument();
  });

  it('keeps the board and refetches after the server refuses a change', async () => {
    setTicketItemStatus.mockRejectedValue(new Error('409'));
    const user = userEvent.setup();
    renderKitchen();
    await screen.findByTestId('ticket-item-1');
    getStationTickets.mockClear();

    await user.click(within(screen.getByTestId('ticket-item-1')).getByRole('button', { name: 'Mulai' }));

    await waitFor(() => expect(getStationTickets).toHaveBeenCalled());
    expect(screen.getByTestId('ticket-item-1')).toBeInTheDocument();
  });

  it('lists fully finished tickets under "Baru selesai", apart from the ones in progress', async () => {
    getStationTickets.mockResolvedValue(feed([
      ticket({ id: 10, items: [item({ id: 1, name: 'Masih Jalan', status: 'preparing' })] }),
      ticket({ id: 11, items: [item({ id: 2, name: 'Sudah Selesai', status: 'served' })] }),
    ]));
    renderKitchen();

    const active = await screen.findByRole('region', { name: 'Sedang berjalan' });
    const recent = screen.getByRole('region', { name: 'Baru selesai' });
    expect(within(active).getByText('Masih Jalan')).toBeInTheDocument();
    expect(within(active).queryByText('Sudah Selesai')).toBeNull();
    expect(within(recent).getByText('Sudah Selesai')).toBeInTheDocument();
  });

  it('shows an empty state when the station has nothing in progress', async () => {
    getStationTickets.mockResolvedValue(feed([]));
    renderKitchen();

    expect(await screen.findByText(/tidak ada tiket yang sedang berjalan/i)).toBeInTheDocument();
  });

  it('explains when no station exists yet', async () => {
    listStations.mockResolvedValue({ data: [] });
    renderKitchen();

    expect(await screen.findByText(/belum ada stasiun/i)).toBeInTheDocument();
    expect(getStationTickets).not.toHaveBeenCalled();
  });

  it('warns, without crashing, when the tickets cannot be loaded', async () => {
    getStationTickets.mockRejectedValue(new Error('offline'));
    renderKitchen();

    expect(await screen.findByRole('alert')).toHaveTextContent(/tidak dapat memuat tiket/i);
  });

  it('opens the print view for a ticket and loads it whole', async () => {
    getTicket.mockResolvedValue(ticket({ items: [item({ name: 'Nasi Goreng' }), item({ id: 2, name: 'Item Lama', status: 'served' })] }));
    const user = userEvent.setup();
    renderKitchen();
    await screen.findByTestId('ticket-10');

    await user.click(screen.getByRole('button', { name: /cetak tiket meja 7/i }));

    const dialog = await screen.findByRole('dialog');
    await waitFor(() => expect(getTicket).toHaveBeenCalledWith(10));
    expect(await within(dialog).findByText('Item Lama')).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Cetak' })).toBeEnabled();
  });
});
