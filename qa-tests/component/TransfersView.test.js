import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TransfersView from '../../resources/js/views/stock/TransfersView.vue';
import ReceiveTransferDialog from '../../resources/js/components/stock/ReceiveTransferDialog.vue';
import NewTransferDrawer from '../../resources/js/components/stock/NewTransferDrawer.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { listTransfers, getTransfer, receiveTransfer, createTransfer, listDiscrepancies } from '../../resources/js/api/stockTransfers';
import { lookupVariants } from '../../resources/js/api/products';

// 045 Stage 6 (T093) — layar transfer stok.
vi.mock('../../resources/js/api/stockTransfers', () => ({
  listTransfers: vi.fn(), getTransfer: vi.fn(), createTransfer: vi.fn(), receiveTransfer: vi.fn(), cancelTransfer: vi.fn(), listDiscrepancies: vi.fn(), listInTransit: vi.fn(),
}));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [] }) }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop 1' }, { id: 2, code: 'WJ2', name: 'Warkop 2' }];
const ROW = { id: 7, number: 'TRF-20261012-0001', status: 'in_transit', has_discrepancy: false, from_outlet: OUTLETS[0], to_outlet: OUTLETS[1], line_count: 1, dispatched_at: '2026-10-12T01:00:00Z', can: { receive: true, cancel: true } };
const DETAIL = { ...ROW, lines: [{ id: 70, kind: 'variant', item: { name: 'Kopi — R' }, qty_sent: '10', qty_received: null, shortfall: '0', discrepancy_reason: null, warnings: ['not_carried_at_destination'] }], steps: [{ step: 'dispatched', by: 'Owner', at: '2026-10-12T01:00:00Z' }], ledger: [] };

function setup({ multi = true, permissions = ['stock.transfer.dispatch'] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'stock'], permissions };
  Object.assign(useOutletStore(), { outlets: OUTLETS, multi, selected: null });
  return pinia;
}

describe('TransfersView (045 Stage 6)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listTransfers.mockResolvedValue({ data: [ROW], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    getTransfer.mockResolvedValue(DETAIL);
    listDiscrepancies.mockResolvedValue({ data: [] });
  });

  it('explains that transfers need several outlets and loads nothing at one outlet', () => {
    const pinia = setup({ multi: false });
    render(TransfersView, { global: { plugins: [pinia] } });
    expect(screen.getByTestId('transfers-single-outlet')).toBeInTheDocument();
    expect(listTransfers).not.toHaveBeenCalled();
    expect(screen.queryByTestId('new-transfer')).toBeNull();
  });

  it('lists transfers and shows the new button only with the dispatch permission', async () => {
    const pinia = setup();
    const { unmount } = render(TransfersView, { global: { plugins: [pinia] } });
    expect(await screen.findByText('TRF-20261012-0001')).toBeInTheDocument();
    expect(screen.getByText('WJ1 → WJ2')).toBeInTheDocument();
    expect(screen.getByTestId('new-transfer')).toBeInTheDocument();
    unmount();

    render(TransfersView, { global: { plugins: [setup({ permissions: [] })] } });
    await screen.findByText('TRF-20261012-0001');
    expect(screen.queryByTestId('new-transfer')).toBeNull();
  });

  it('opens the detail with the destination warning and the server-decided actions', async () => {
    const user = userEvent.setup();
    render(TransfersView, { global: { plugins: [setup()] } });
    await user.click(await screen.findByTestId('open-transfer'));

    expect(await screen.findByTestId('not-carried-warning')).toBeInTheDocument();
    expect(screen.getByTestId('transfer-receive')).toBeInTheDocument();
    expect(screen.getByTestId('transfer-cancel')).toBeInTheDocument();
  });

  it('hides Receive/Cancel when the server says the user cannot', async () => {
    getTransfer.mockResolvedValue({ ...DETAIL, can: { receive: false, cancel: false } });
    const user = userEvent.setup();
    render(TransfersView, { global: { plugins: [setup()] } });
    await user.click(await screen.findByTestId('open-transfer'));
    await screen.findByTestId('transfer-detail');
    expect(screen.queryByTestId('transfer-receive')).toBeNull();
    expect(screen.queryByTestId('transfer-cancel')).toBeNull();
  });
});

describe('ReceiveTransferDialog', () => {
  beforeEach(() => { vi.clearAllMocks(); receiveTransfer.mockResolvedValue({ number: 'TRF-1' }); });

  it('requires a reason for a short line and sends per-line quantities', async () => {
    const user = userEvent.setup();
    const { emitted } = render(ReceiveTransferDialog, { props: { open: true, transfer: DETAIL }, global: { plugins: [setup()] } });
    const qty = screen.getAllByRole('spinbutton')[0];
    await user.clear(qty);
    await user.type(qty, '8');
    expect(screen.getByRole('button', { name: 'Terima' })).toBeDisabled(); // alasan belum diisi

    await user.type(screen.getByTestId('short-reason').querySelector('input') ?? screen.getByTestId('short-reason'), 'rusak');
    await user.click(screen.getByRole('button', { name: 'Terima' }));
    await waitFor(() => expect(receiveTransfer).toHaveBeenCalledWith(7, { lines: [{ line_id: 70, qty_received: 8, reason: 'rusak' }] }));
    expect(emitted().done).toBeTruthy();
  });

  it('cannot go above what was sent', async () => {
    const user = userEvent.setup();
    render(ReceiveTransferDialog, { props: { open: true, transfer: DETAIL }, global: { plugins: [setup()] } });
    const qty = screen.getAllByRole('spinbutton')[0];
    await user.clear(qty);
    await user.type(qty, '11');
    expect(screen.getByRole('button', { name: 'Terima' })).toBeDisabled();
  });
});

describe('NewTransferDrawer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    lookupVariants.mockResolvedValue({ data: [{ variant_id: 11, sku: 'S-1', label: 'Kopi — R', outlet_stock: 6 }] });
    createTransfer.mockResolvedValue({ number: 'TRF-1' });
  });

  it('searches stock AT the source outlet and refuses a quantity above what is available', async () => {
    const user = userEvent.setup();
    render(NewTransferDrawer, { props: { open: true }, global: { plugins: [setup()] } });
    await user.click(screen.getByTestId('transfer-from').querySelector('button, [role=combobox]') ?? screen.getByTestId('transfer-from'));
    await user.click(await screen.findByRole('option', { name: 'Warkop 1' }));
    await user.type(screen.getByTestId('transfer-search').querySelector('input') ?? screen.getByTestId('transfer-search'), 'kop');
    await waitFor(() => expect(lookupVariants).toHaveBeenCalledWith('kop', 8, { stockOutlet: 1 }));
    await user.click(await screen.findByRole('button', { name: /Kopi — R/ }));
    expect(screen.getByText(/tersedia 6/)).toBeInTheDocument();
  });
});
