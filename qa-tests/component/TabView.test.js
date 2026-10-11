import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import TabView from '../../resources/js/views/TabView.vue';
import { getTab, addTabLine, updateTabLine, removeTabLine, payTab, closeEmptyTab, sendTab, moveTab, mergeTab, splitTab, listTabs, voidTab } from '../../resources/js/api/tabs';
import { getFloor } from '../../resources/js/api/floor';
import { setTicketItemStatus } from '../../resources/js/api/kitchen';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listProducts } from '../../resources/js/api/products';
import { getReceipt } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/tabs', () => ({
  getTab: vi.fn(), addTabLine: vi.fn(), updateTabLine: vi.fn(), removeTabLine: vi.fn(), payTab: vi.fn(), closeEmptyTab: vi.fn(),
  openTab: vi.fn(), listTabs: vi.fn(), sendTab: vi.fn(), moveTab: vi.fn(), mergeTab: vi.fn(), splitTab: vi.fn(), voidTab: vi.fn(),
}));
vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn(), lookupVariants: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [{ id: 1, code: 'MK', name: 'Makanan' }] }) }));
vi.mock('../../resources/js/api/kitchen', () => ({ setTicketItemStatus: vi.fn(), getStationTickets: vi.fn(), getTicket: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
}));
vi.mock('../../resources/js/api/orders', () => ({ getReceipt: vi.fn().mockResolvedValue(null) }));

const line = (over) => ({
  id: 11, variant_id: 5, name: 'Es Kopi', qty: 2, unit_price: '25000.00', line_total: '50000.00',
  modifiers: [], note: null, sent: false, prep_status: null, station: null, ...over,
});
const tab = (over) => ({
  id: 7, order_number: 'TRX-1', status: 'open', service_type: 'dine_in', table: { id: 1, name: '7', area: '' }, tab_label: null,
  session_id: 1, subtotal: '50000.00', discount_amount: '0.00', total_amount: '50000.00', paid_amount: '0.00', change_amount: '0.00',
  balance: '50000.00', lines: [line()], payments: [], ...over,
});
const variant = (over) => ({ id: 5, sku: 'S1', variant_name: 'Standard', sell_price: '25000.00', current_stock: 10, is_active: true, track_stock: true, ...over });
const product = (over) => ({ id: 1, name: 'Es Kopi', category_id: 1, artist_name: 'Restoran', image_url: null, variants: [variant()], ...over });

async function renderTab(initialTab = tab(), menuKeys = ['floor']) {
  getTab.mockResolvedValue(initialTab);
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Kasir', name: 'Kasir', menu_keys: menuKeys };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/floor', name: 'floor', component: { template: '<div />' } },
      { path: '/floor/tabs/:id', name: 'floor-tab', component: TabView },
    ],
  });
  router.push('/floor/tabs/7');
  await router.isReady();
  const utils = render(TabView, { global: { plugins: [pinia, router] } });
  await screen.findByTestId('tab-total');
  return { ...utils, router };
}

describe('TabView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [product()] });
  });

  it('shows the place, number, lines and the totals exactly as the server sent them', async () => {
    await renderTab(tab({ total_amount: '48000.00', subtotal: '50000.00', discount_amount: '2000.00' }));

    expect(screen.getByRole('heading', { name: 'Meja 7' })).toBeInTheDocument();
    expect(screen.getByText('TRX-1')).toBeInTheDocument();
    expect(screen.getByTestId('line-11')).toHaveTextContent('Es Kopi');
    // Total dari server (48.000), BUKAN dihitung ulang dari baris (50.000).
    expect(screen.getByTestId('tab-total')).toHaveTextContent('48.000');
  });

  it('adds a single-variant menu item straight away and shows the server\'s new tab', async () => {
    addTabLine.mockResolvedValue(tab({ total_amount: '75000.00', lines: [line(), line({ id: 12, name: 'Nasi Goreng', qty: 1, line_total: '25000.00' })] }));
    const user = userEvent.setup();
    await renderTab();

    await user.click(await screen.findByTestId('menu-1'));

    expect(addTabLine).toHaveBeenCalledWith(7, { variant_id: 5, qty: 1 });
    await waitFor(() => expect(screen.getByTestId('tab-total')).toHaveTextContent('75.000'));
    expect(screen.getByTestId('line-12')).toBeInTheDocument();
  });

  it('opens the variant picker for a multi-variant item and adds the chosen one', async () => {
    listProducts.mockResolvedValue({ data: [product({ variants: [variant({ id: 5, variant_name: 'Regular' }), variant({ id: 6, sku: 'S2', variant_name: 'Large', sell_price: '30000.00' })] })] });
    addTabLine.mockResolvedValue(tab());
    const user = userEvent.setup();
    await renderTab();

    await user.click(await screen.findByTestId('menu-1'));
    expect(addTabLine).not.toHaveBeenCalled();
    await user.click(await screen.findByText('Large'));

    expect(addTabLine).toHaveBeenCalledWith(7, { variant_id: 6, qty: 1 });
  });

  it('greys out a tracked item with no stock, but never a made-to-order dish', async () => {
    listProducts.mockResolvedValue({ data: [
      product({ id: 1, name: 'Habis', variants: [variant({ current_stock: 0, track_stock: true })] }),
      product({ id: 2, name: 'Nasi Goreng', variants: [variant({ id: 8, current_stock: 0, track_stock: false })] }),
    ] });
    await renderTab();

    expect(await screen.findByTestId('menu-1')).toBeDisabled();
    expect(screen.getByTestId('menu-2')).toBeEnabled();
  });

  it('changes a quantity through the API', async () => {
    updateTabLine.mockResolvedValue(tab({ lines: [line({ qty: 2, line_total: '50000.00' })] }));
    const user = userEvent.setup();
    await renderTab(tab({ lines: [line({ qty: 1 })] }));

    await user.click(screen.getByRole('button', { name: /tambah es kopi/i }));

    expect(updateTabLine).toHaveBeenCalledWith(7, 11, { qty: 2 });
    await waitFor(() => expect(screen.getByTestId('qty-11')).toHaveTextContent('2'));
  });

  it('removes a line when its quantity is decreased to zero', async () => {
    removeTabLine.mockResolvedValue(tab({ lines: [], total_amount: '0.00', subtotal: '0.00', balance: '0.00' }));
    const user = userEvent.setup();
    await renderTab(tab({ lines: [line({ qty: 1 })] }));

    await user.click(screen.getByRole('button', { name: /kurangi es kopi/i }));

    expect(removeTabLine).toHaveBeenCalledWith(7, 11);
    expect(updateTabLine).not.toHaveBeenCalled();
    expect(await screen.findByText(/belum ada item/i)).toBeInTheDocument();
  });

  it('saves a note when the field loses focus, and only if it changed', async () => {
    updateTabLine.mockResolvedValue(tab({ lines: [line({ note: 'tanpa es' })] }));
    const user = userEvent.setup();
    await renderTab();

    const note = screen.getByLabelText(/catatan untuk es kopi/i);
    await user.click(note);
    await user.tab(); // blur tanpa perubahan
    expect(updateTabLine).not.toHaveBeenCalled();

    await user.type(note, 'tanpa es');
    await user.tab();
    expect(updateTabLine).toHaveBeenCalledWith(7, 11, { note: 'tanpa es' });
  });

  it('gives a sent line no edit controls', async () => {
    await renderTab(tab({ lines: [line({ sent: true, prep_status: 'preparing' })] }));

    const row = screen.getByTestId('line-11');
    // Status dapur menggantikan label "Terkirim" begitu tiketnya ada.
    expect(within(row).getByText(/dimasak/i)).toBeInTheDocument();
    expect(within(row).queryByRole('button')).toBeNull();
  });

  it('lets an empty tab be closed, after confirmation', async () => {
    closeEmptyTab.mockResolvedValue();
    const user = userEvent.setup();
    const { router } = await renderTab(tab({ lines: [], total_amount: '0.00', subtotal: '0.00', balance: '0.00' }));

    expect(screen.getByRole('button', { name: 'Bayar' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Tutup tab kosong' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Tutup tab kosong' }));

    await waitFor(() => expect(closeEmptyTab).toHaveBeenCalledWith(7));
    await waitFor(() => expect(router.currentRoute.value.name).toBe('floor'));
  });

  it('pays the whole balance with a client_ref, then shows the receipt', async () => {
    payTab.mockResolvedValue(tab({ status: 'completed', paid_amount: '50000.00', balance: '0.00' }));
    const user = userEvent.setup();
    await renderTab();

    await user.click(screen.getByRole('button', { name: 'Bayar' }));
    await user.click(await screen.findByRole('button', { name: /konfirmasi pembayaran/i }));

    await waitFor(() => expect(payTab).toHaveBeenCalledTimes(1));
    const [id, body] = payTab.mock.calls[0];
    expect(id).toBe(7);
    expect(body.client_ref).toMatch(/^[0-9a-f-]{36}$/);
    expect(body.payments).toHaveLength(1);
    expect(body.payments[0]).toMatchObject({ method: 'cash' });
    expect(Number(body.payments[0].amount)).toBe(50000);
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(7));
  });

  it('records a partial payment and keeps the tab open with the new balance', async () => {
    payTab.mockResolvedValue(tab({ paid_amount: '20000.00', balance: '30000.00' }));
    const user = userEvent.setup();
    await renderTab();

    await user.click(screen.getByRole('button', { name: 'Bayar' }));
    await user.click(await screen.findByRole('radio', { name: 'Bayar sebagian' }));
    await fireEvent.update(screen.getByLabelText(/jumlah dibayar sekarang/i), '20000');
    await user.click(screen.getByRole('button', { name: 'Terapkan' }));
    await user.click(await screen.findByRole('button', { name: /konfirmasi pembayaran/i }));

    await waitFor(() => expect(payTab).toHaveBeenCalledTimes(1));
    expect(Number(payTab.mock.calls[0][1].payments[0].amount)).toBe(20000);
    expect(getReceipt).not.toHaveBeenCalled();
    expect(await screen.findByTestId('tab-balance')).toHaveTextContent('30.000');
  });

  it('reuses the same client_ref when a failed payment is submitted again', async () => {
    payTab.mockRejectedValueOnce(new Error('offline')).mockResolvedValue(tab({ status: 'completed', balance: '0.00' }));
    const user = userEvent.setup();
    await renderTab();

    await user.click(screen.getByRole('button', { name: 'Bayar' }));
    await user.click(await screen.findByRole('button', { name: /konfirmasi pembayaran/i }));
    await waitFor(() => expect(payTab).toHaveBeenCalledTimes(1));
    await user.click(await screen.findByRole('button', { name: /konfirmasi pembayaran/i }));
    await waitFor(() => expect(payTab).toHaveBeenCalledTimes(2));

    expect(payTab.mock.calls[1][1].client_ref).toBe(payTab.mock.calls[0][1].client_ref);
  });

  it('is read-only once the tab is paid', async () => {
    await renderTab(tab({ status: 'completed', paid_amount: '50000.00', balance: '0.00' }));

    expect(screen.getByText('Tab ini sudah lunas.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Bayar' })).toBeNull();
    expect(await screen.findByTestId('menu-1')).toBeDisabled();
  });

  it('opens the modifier picker for a menu item that has modifiers, and sends the choices and note', async () => {
    const size = { id: 1, name: 'Size', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, options: [
      { id: 10, name: 'Regular', price_delta: '0.00' }, { id: 11, name: 'Large', price_delta: '5000.00' },
    ] };
    listProducts.mockResolvedValue({ data: [product({ modifier_groups: [size] })] });
    addTabLine.mockResolvedValue(tab());
    const user = userEvent.setup();
    await renderTab();

    await user.click(await screen.findByTestId('menu-1'));
    expect(addTabLine).not.toHaveBeenCalled(); // pemilih modifier dulu
    await user.click(await screen.findByLabelText(/large/i));
    await user.type(screen.getByLabelText(/catatan untuk dapur/i), 'tanpa es');
    await user.click(screen.getByRole('button', { name: 'Tambah ke tab' }));

    await waitFor(() => expect(addTabLine).toHaveBeenCalledTimes(1));
    expect(addTabLine).toHaveBeenCalledWith(7, { variant_id: 5, qty: 1, modifier_option_ids: [11], note: 'tanpa es' });
  });

  it('asks for the variant first, then for the modifiers', async () => {
    const size = { id: 1, name: 'Size', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, options: [{ id: 10, name: 'Regular', price_delta: '0.00' }] };
    listProducts.mockResolvedValue({ data: [product({
      variants: [variant({ id: 5, variant_name: 'Hot' }), variant({ id: 6, sku: 'S2', variant_name: 'Iced' })],
      modifier_groups: [size],
    })] });
    addTabLine.mockResolvedValue(tab());
    const user = userEvent.setup();
    await renderTab();

    await user.click(await screen.findByTestId('menu-1'));
    await user.click(await screen.findByText('Iced'));
    await user.click(await screen.findByLabelText(/regular/i));
    await user.click(screen.getByRole('button', { name: 'Tambah ke tab' }));

    await waitFor(() => expect(addTabLine).toHaveBeenCalledWith(7, { variant_id: 6, qty: 1, modifier_option_ids: [10] }));
  });

  it('keeps the modifier picker open when the server refuses the choices', async () => {
    const size = { id: 1, name: 'Size', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, options: [{ id: 10, name: 'Regular', price_delta: '0.00' }] };
    listProducts.mockResolvedValue({ data: [product({ modifier_groups: [size] })] });
    addTabLine.mockRejectedValue(new Error('422'));
    const user = userEvent.setup();
    await renderTab();

    await user.click(await screen.findByTestId('menu-1'));
    await user.click(await screen.findByLabelText(/regular/i));
    await user.click(screen.getByRole('button', { name: 'Tambah ke tab' }));

    await waitFor(() => expect(addTabLine).toHaveBeenCalled());
    expect(screen.getByRole('button', { name: 'Tambah ke tab' })).toBeInTheDocument();
  });

  it('shows the chosen options of a line under its name', async () => {
    await renderTab(tab({ lines: [line({ modifiers: [{ group: 'Size', option: 'Large', price_delta: '5000.00' }] })] }));

    expect(within(screen.getByTestId('line-11')).getByText('Large')).toBeInTheDocument();
  });

  // --- kirim ke dapur & antar (US4) ---------------------------------------------

  it('offers to send the unsent items to the kitchen, showing how many', async () => {
    sendTab.mockResolvedValue({ tab: tab({ lines: [line({ sent: true, prep_status: 'new', ticket_item_id: 90, station: { id: 1, name: 'Dapur' } })] }), tickets: [{ id: 5 }] });
    const user = userEvent.setup();
    await renderTab(tab({ lines: [line(), line({ id: 12, name: 'Nasi', sent: false })] }));

    await user.click(screen.getByRole('button', { name: /kirim ke dapur \(2\)/i }));

    expect(sendTab).toHaveBeenCalledWith(7);
    // Tab dari respons menggantikan layar: baris kini berstatus & berstasiun.
    expect(await screen.findByTestId('prep-11')).toHaveTextContent(/baru.*dapur/i);
    expect(screen.queryByRole('button', { name: /kirim ke dapur/i })).toBeNull();
  });

  it('shows no send button when nothing is waiting to be sent', async () => {
    await renderTab(tab({ lines: [line({ sent: true, prep_status: 'new' })] }));

    expect(screen.queryByRole('button', { name: /kirim ke dapur/i })).toBeNull();
  });

  it('lets a waiter mark a ready item as served, then reloads the tab', async () => {
    setTicketItemStatus.mockResolvedValue({ status: 'served' });
    const user = userEvent.setup();
    await renderTab(tab({ lines: [line({ sent: true, prep_status: 'ready', ticket_item_id: 90 })] }));
    getTab.mockResolvedValue(tab({ lines: [line({ sent: true, prep_status: 'served', ticket_item_id: 90 })] }));

    await user.click(screen.getByRole('button', { name: 'Sudah diantar' }));

    expect(setTicketItemStatus).toHaveBeenCalledWith(90, 'served');
    expect(await screen.findByTestId('prep-11')).toHaveTextContent(/diantar/i);
    expect(screen.queryByRole('button', { name: 'Sudah diantar' })).toBeNull();
  });

  it('does not offer to serve an item that is not ready yet', async () => {
    await renderTab(tab({ lines: [line({ sent: true, prep_status: 'preparing', ticket_item_id: 90 })] }));

    expect(screen.queryByRole('button', { name: 'Sudah diantar' })).toBeNull();
  });

  it('does not offer serving to a role that may neither serve nor cook', async () => {
    await renderTab(tab({ lines: [line({ sent: true, prep_status: 'ready', ticket_item_id: 90 })] }), ['dashboard']);

    expect(screen.queryByRole('button', { name: 'Sudah diantar' })).toBeNull();
  });

  // --- pindah / gabung / pisah (US5) ----------------------------------------------

  it('moves the tab to a free table chosen in the dialog and shows the new place', async () => {
    getFloor.mockResolvedValue({ areas: [{ area: 'Indoor', tables: [{ id: 9, name: '9', seats: 4, is_active: true, state: 'free', tab: null }] }], takeaway_tabs: [], version: 'v' });
    moveTab.mockResolvedValue(tab({ table: { id: 9, name: '9', area: 'Indoor' } }));
    const user = userEvent.setup();
    await renderTab();

    await user.click(screen.getByRole('button', { name: 'Pindah meja' }));
    await user.click(await screen.findByRole('button', { name: 'Pindah ke meja 9' }));

    expect(moveTab).toHaveBeenCalledWith(7, 9);
    expect(await screen.findByRole('heading', { name: 'Meja 9' })).toBeInTheDocument();
  });

  it('merges another tab into this one and replaces the screen with the surviving tab', async () => {
    listTabs.mockResolvedValue({ data: [{ id: 8, order_number: 'TRX-8', table: { id: 2, name: '8' }, tab_label: null, total_amount: '25000.00', session_id: 1 }] });
    mergeTab.mockResolvedValue(tab({ total_amount: '75000.00', subtotal: '75000.00', lines: [line(), line({ id: 12, name: 'Dari tab lain', qty: 1 })] }));
    const user = userEvent.setup();
    await renderTab();

    await user.click(screen.getByRole('button', { name: 'Gabung tab' }));
    await user.click(await screen.findByLabelText(/meja 8/i));
    await user.click(screen.getByRole('button', { name: 'Gabungkan' }));

    expect(mergeTab).toHaveBeenCalledWith(7, 8);
    expect(await screen.findByTestId('line-12')).toBeInTheDocument();
    expect(screen.getByTestId('tab-total')).toHaveTextContent('75.000');
  });

  it('splits the bill: sends the selection with a client_ref, then shows the receipt of the NEW sale', async () => {
    splitTab.mockResolvedValue({
      paid_order: { id: 55, status: 'completed' },
      tab: tab({ total_amount: '25000.00', subtotal: '25000.00', lines: [line({ qty: 1, line_total: '25000.00' })] }),
    });
    const user = userEvent.setup();
    await renderTab(tab({ lines: [line({ qty: 2 })] }));

    await user.click(screen.getByRole('button', { name: 'Pisah tagihan' }));
    await user.click(within(await screen.findByTestId('split-line-11')).getByRole('button', { name: /tambah es kopi/i }));
    await user.click(await screen.findByRole('button', { name: /bayar bagian ini/i }));

    await waitFor(() => expect(splitTab).toHaveBeenCalledTimes(1));
    const [id, body] = splitTab.mock.calls[0];
    expect(id).toBe(7);
    expect(body.client_ref).toMatch(/^[0-9a-f-]{36}$/);
    expect(body.items).toEqual([{ item_id: 11, qty: 1 }]);
    expect(Number(body.payments[0].amount)).toBe(25000);
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(55)); // struk penjualan terpisah, bukan tab
    await waitFor(() => expect(screen.getByTestId('tab-total')).toHaveTextContent('25.000'));
  });

  it('keeps the split dialog open and reuses the client_ref when the split is refused', async () => {
    splitTab.mockRejectedValueOnce(new Error('409')).mockResolvedValue({ paid_order: { id: 56 }, tab: tab() });
    const user = userEvent.setup();
    await renderTab(tab({ lines: [line({ qty: 2 })] }));

    await user.click(screen.getByRole('button', { name: 'Pisah tagihan' }));
    await user.click(within(await screen.findByTestId('split-line-11')).getByRole('button', { name: /tambah es kopi/i }));
    await user.click(await screen.findByRole('button', { name: /bayar bagian ini/i }));
    await waitFor(() => expect(splitTab).toHaveBeenCalledTimes(1));

    await user.click(await screen.findByRole('button', { name: /bayar bagian ini/i }));
    await waitFor(() => expect(splitTab).toHaveBeenCalledTimes(2));

    expect(splitTab.mock.calls[1][1].client_ref).toBe(splitTab.mock.calls[0][1].client_ref);
  });

  it('leaves for the floor after the receipt when the split used up every line', async () => {
    splitTab.mockResolvedValue({ paid_order: { id: 57 }, tab: tab({ status: 'voided', lines: [], total_amount: '0.00', subtotal: '0.00', balance: '0.00' }) });
    const user = userEvent.setup();
    const { router } = await renderTab(tab({ lines: [line({ qty: 1 })] }));

    await user.click(screen.getByRole('button', { name: 'Pisah tagihan' }));
    await user.click(within(await screen.findByTestId('split-line-11')).getByRole('button', { name: /tambah es kopi/i }));
    await user.click(await screen.findByRole('button', { name: /bayar bagian ini/i }));
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(57));
    await user.click(await screen.findByRole('button', { name: 'Kembali ke tab' }));

    await waitFor(() => expect(router.currentRoute.value.name).toBe('floor'));
  });

  it('offers the three arrangements only while the tab is open, and Split only when it has lines', async () => {
    await renderTab(tab({ lines: [] , total_amount: '0.00', subtotal: '0.00', balance: '0.00' }));

    expect(screen.getByRole('button', { name: 'Pindah meja' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Gabung tab' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Pisah tagihan' })).toBeDisabled();
  });

  it('hides the arrangement buttons once the tab is paid', async () => {
    await renderTab(tab({ status: 'completed', balance: '0.00', paid_amount: '50000.00' }));

    expect(screen.queryByRole('button', { name: 'Pindah meja' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Pisah tagihan' })).toBeNull();
  });

  // --- batalkan item / batalkan tab (US6) ------------------------------------------

  const sentLine = (status, over = {}) => line({ sent: true, prep_status: status, ticket_item_id: 90, station: { id: 1, name: 'Dapur' }, ...over });

  it('lets any floor user cancel a sent item that has not been cooked yet', async () => {
    removeTabLine.mockResolvedValue(tab({ lines: [], total_amount: '0.00', subtotal: '0.00', balance: '0.00' }));
    const user = userEvent.setup();
    await renderTab(tab({ lines: [sentLine('new')] }));

    await user.click(screen.getByRole('button', { name: /batalkan es kopi/i }));
    const dialog = await screen.findByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Batalkan item' });
    expect(confirm).toBeDisabled(); // alasan wajib
    await user.type(within(dialog).getByLabelText(/alasan/i), 'salah pesan');
    await user.click(confirm);

    expect(removeTabLine).toHaveBeenCalledWith(7, 11, 'salah pesan');
    expect(await screen.findByText(/belum ada item/i)).toBeInTheDocument();
  });

  it('hides the cancel control for an item that is already being cooked, unless the user is an owner/admin', async () => {
    await renderTab(tab({ lines: [sentLine('preparing')] }), ['floor']);
    expect(screen.queryByRole('button', { name: /batalkan es kopi/i })).toBeNull();
  });

  it('shows the cancel control after cooking started to an owner/admin (the settings gate)', async () => {
    for (const status of ['preparing', 'ready', 'served']) {
      const { unmount } = await renderTab(tab({ lines: [sentLine(status)] }), ['floor', 'settings']);
      expect(screen.getByRole('button', { name: /batalkan es kopi/i })).toBeInTheDocument();
      unmount();
    }
  });

  it('never offers cancelling on an unsent line (it is simply removed with the trash button)', async () => {
    await renderTab(tab({ lines: [line()] }), ['floor', 'settings']);

    expect(screen.queryByRole('button', { name: /batalkan es kopi/i })).toBeNull();
    expect(screen.getByRole('button', { name: /hapus es kopi/i })).toBeInTheDocument();
  });

  it('keeps the dialog open, with the reason, when the server refuses the cancellation', async () => {
    removeTabLine.mockRejectedValue(new Error('403'));
    const user = userEvent.setup();
    await renderTab(tab({ lines: [sentLine('new')] }));

    await user.click(screen.getByRole('button', { name: /batalkan es kopi/i }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/alasan/i), 'salah pesan');
    await user.click(within(dialog).getByRole('button', { name: 'Batalkan item' }));

    await waitFor(() => expect(removeTabLine).toHaveBeenCalled());
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(within(screen.getByRole('dialog')).getByLabelText(/alasan/i)).toHaveValue('salah pesan');
  });

  it('offers "Batalkan tab" only to an owner/admin, on an open tab with no payments', async () => {
    await renderTab(tab(), ['floor']);
    expect(screen.queryByRole('button', { name: 'Batalkan tab' })).toBeNull();
  });

  it('hides "Batalkan tab" once any payment is recorded, even for an owner/admin', async () => {
    await renderTab(tab({ paid_amount: '5000.00', balance: '45000.00' }), ['floor', 'settings']);
    expect(screen.queryByRole('button', { name: 'Batalkan tab' })).toBeNull();
  });

  it('voids the tab with a reason and leaves for the floor', async () => {
    voidTab.mockResolvedValue({ status: 'voided' });
    const user = userEvent.setup();
    const { router } = await renderTab(tab(), ['floor', 'settings']);

    await user.click(screen.getByRole('button', { name: 'Batalkan tab' }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/alasan/i), 'tamu pergi');
    await user.click(within(dialog).getByRole('button', { name: 'Batalkan tab' }));

    expect(voidTab).toHaveBeenCalledWith(7, 'tamu pergi');
    await waitFor(() => expect(router.currentRoute.value.name).toBe('floor'));
  });

  it('stays on the tab when voiding is refused (e.g. a payment was just recorded elsewhere)', async () => {
    voidTab.mockRejectedValue(new Error('409'));
    const user = userEvent.setup();
    const { router } = await renderTab(tab(), ['floor', 'settings']);

    await user.click(screen.getByRole('button', { name: 'Batalkan tab' }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/alasan/i), 'tamu pergi');
    await user.click(within(dialog).getByRole('button', { name: 'Batalkan tab' }));

    await waitFor(() => expect(voidTab).toHaveBeenCalled());
    expect(router.currentRoute.value.name).toBe('floor-tab');
  });
});
