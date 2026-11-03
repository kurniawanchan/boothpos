import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import SelfServiceOrdersPanel from '../../resources/js/components/selfservice/SelfServiceOrdersPanel.vue';
import { getSelfServiceOrder, listSelfServiceOrders, fetchProofBlob } from '../../resources/js/api/selfServiceOrders';
import { useAuthStore } from '../../resources/js/stores/auth';

vi.mock('../../resources/js/api/selfServiceOrders', () => ({
  listSelfServiceOrders: vi.fn(), getSelfServiceOrder: vi.fn(), verifySelfServiceOrder: vi.fn(), rejectSelfServiceOrder: vi.fn(),
  dispatchSelfServiceOrder: vi.fn(), fetchProofBlob: vi.fn(),
}));

const row = (over = {}) => ({
  id: 1, reference_no: 'SS-251103-001', status: 'awaiting_verification', created_at: '2026-11-03T05:00:00+00:00', table: { id: 5, name: '5' },
  service_type: 'dine_in', item_count: 3, subtotal: '55000.00', tax_amount: '0.00', total_amount: '55000.00',
  payment: { method: 'qris', status: 'waiting_verification', proof_id: 9 }, ...over,
});
const page = (rows, counts = {}) => ({
  data: rows,
  meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1, counts: { awaiting_verification: 1, verified: 0, dispatched: 0, rejected: 0, pending: 1, ...counts } },
});

function mountPanel() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: ['self_service'], permissions: [] };
  return render(SelfServiceOrdersPanel, { global: { plugins: [pinia] } });
}

describe('SelfServiceOrdersPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    URL.createObjectURL = vi.fn(() => 'blob:proof');
    URL.revokeObjectURL = vi.fn();
    listSelfServiceOrders.mockResolvedValue(page([row(), row({ id: 2, reference_no: 'SS-251103-002', status: 'verified', table: { id: 6, name: '6' }, service_type: 'takeaway' })]));
    fetchProofBlob.mockResolvedValue(new Blob(['x'], { type: 'image/jpeg' }));
  });
  afterEach(() => vi.useRealTimers());

  it('opens on the orders that need action, oldest first as the server sends them', async () => {
    mountPanel();

    expect(await screen.findByText('SS-251103-001')).toBeInTheDocument();
    expect(screen.getByText('SS-251103-002')).toBeInTheDocument();
    expect(listSelfServiceOrders).toHaveBeenCalledWith(expect.objectContaining({ status: ['awaiting_verification', 'verified'], page: 1 }));
    expect(screen.getByText('Menunggu verifikasi')).toBeInTheDocument();
    expect(screen.getByText('Terverifikasi')).toBeInTheDocument();
    expect(screen.getByText('Take Away')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 55.000', { selector: 'span' })).toHaveLength(2);
  });

  it('tells the page how many orders are waiting so the tab badge stays current', async () => {
    const { emitted } = mountPanel();
    await screen.findByText('SS-251103-001');

    expect(emitted().counts[0][0]).toMatchObject({ pending: 1 });
  });

  it('says so when nothing is waiting', async () => {
    listSelfServiceOrders.mockResolvedValue(page([], { pending: 0, awaiting_verification: 0 }));
    mountPanel();

    await waitFor(() => expect(screen.getByText('Tidak ada pesanan self-service yang menunggu tindakan.')).toBeInTheDocument());
  });

  it('switches the status filter and goes back to page 1', async () => {
    const user = userEvent.setup();
    mountPanel();
    await screen.findByText('SS-251103-001');

    await user.click(screen.getByRole('combobox', { name: /status/i }));
    await user.click(await screen.findByRole('option', { name: 'Ditolak' }));

    await waitFor(() => expect(listSelfServiceOrders).toHaveBeenLastCalledWith(expect.objectContaining({ status: ['rejected'], page: 1 })));
  });

  it('searches after a short pause rather than on every key', async () => {
    const user = userEvent.setup();
    mountPanel();
    await screen.findByText('SS-251103-001');
    listSelfServiceOrders.mockClear();

    await user.type(screen.getByRole('searchbox'), 'Meja 5');

    expect(listSelfServiceOrders).not.toHaveBeenCalled();
    await waitFor(() => expect(listSelfServiceOrders).toHaveBeenCalledTimes(1), { timeout: 2000 });
    expect(listSelfServiceOrders).toHaveBeenCalledWith(expect.objectContaining({ q: 'Meja 5' }));
  });

  it('opens the detail drawer for the chosen order', async () => {
    getSelfServiceOrder.mockResolvedValue({ ...row(), lines: [{ name: 'Kopi Susu', qty: 2, unit_price: '20000.00', line_total: '40000.00', note: null, modifiers: [] }], rejection: null, order: null });
    const user = userEvent.setup();
    mountPanel();
    const first = (await screen.findByText('SS-251103-001')).closest('tr');

    await user.click(within(first).getByRole('button', { name: 'Buka' }));

    expect(await screen.findByTestId('self-service-drawer')).toBeInTheDocument();
    expect(getSelfServiceOrder).toHaveBeenCalledWith(1);
    expect(screen.getByText('2× Kopi Susu')).toBeInTheDocument();
  });

  it('keeps the list and offers a retry when loading fails', async () => {
    listSelfServiceOrders.mockRejectedValueOnce(new Error('offline'));
    mountPanel();

    expect(await screen.findByRole('button', { name: /coba lagi/i })).toBeInTheDocument();
  });
});
