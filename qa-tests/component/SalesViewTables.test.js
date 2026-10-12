import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getFloor } from '../../resources/js/api/floor';
import * as tabsApi from '../../resources/js/api/tabs';
import { getOrder } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/tabs', () => ({
  getTab: vi.fn(), openTab: vi.fn(), closeEmptyTab: vi.fn(), addTabLine: vi.fn(), updateTabLine: vi.fn(), removeTabLine: vi.fn(),
  voidTab: vi.fn(), payTab: vi.fn(), sendTab: vi.fn(), moveTab: vi.fn(), mergeTab: vi.fn(), splitTab: vi.fn(),
}));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
  updatePaymentConfirmation: vi.fn(),
  getPaymentProofBlobUrl: vi.fn(),
  verifyPayment: vi.fn(),
  verifyOrderPayments: vi.fn(),
}));

// 044-sales-table-view (US1+US3) — alur utuh di halaman Sales: ubin → panel detail → tutup → fokus kembali ke ubin,
// ubin kosong tidak membuka apa pun, dan order yang ternyata sudah dibayar membuka popup transaksi yang sudah ada.
const OPENED = new Date(Date.now() - 19 * 60_000).toISOString();
const SNAPSHOT = {
  areas: [{ area: 'Indoor', tables: [
    { id: 1, name: 'T1', seats: 4, is_active: true, state: 'free', tab: null },
    { id: 4, name: 'T4', seats: 4, is_active: true, state: 'occupied', tab: { id: 9, order_number: 'WJM-0009', total_amount: '35000.00', paid_amount: '0.00', opened_at: OPENED, session_id: 1, ready_count: 0, unsent_count: 0 } },
  ] }],
  takeaway_tabs: [],
  version: 'v1',
};
const TAB = {
  id: 9, order_number: 'WJM-0009', status: 'open', table: { id: 4, name: 'T4', area: 'Indoor' }, tab_label: null, opened_by: 'Rina',
  subtotal: '35000.00', discount_amount: '0.00', total_amount: '35000.00', paid_amount: '0.00', change_amount: '0.00', balance: '35000.00',
  created_at: OPENED,
  lines: [{ id: 1, name: 'Kopi Susu', qty: 1, unit_price: '35000.00', line_total: '35000.00', modifiers: [], note: null, sent: false, prep_status: null, station: null }],
  payments: [],
};

async function mountTables() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['sales', 'floor'] };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/floor/tabs/:id', name: 'floor-tab', component: { template: '<div />' } }],
  });
  router.push('/?view=tables');
  await router.isReady();
  render(SalesView, { global: { plugins: [pinia, router] } });
  return userEvent.setup();
}

describe('SalesView — papan Meja → detail order (044)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: [] });
    getFloor.mockResolvedValue(SNAPSHOT);
    tabsApi.getTab.mockResolvedValue(TAB);
    getOrder.mockResolvedValue({ id: 9, order_number: 'WJM-0009', status: 'completed', items: [], payments: [], total_amount: '35000.00' });
  });

  it('klik ubin terisi membuka detail hanya-baca; Escape menutup dan fokus kembali ke ubin yang sama', async () => {
    const user = await mountTables();

    const tile = await screen.findByRole('button', { name: /^T4, order WJM-0009/ });
    await user.click(tile);

    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText('Kopi Susu')).toBeInTheDocument();
    expect(tabsApi.getTab).toHaveBeenCalledWith(9);

    await user.keyboard('{Escape}');
    // Escape hanya didengar di dalam panel; kirim ke elemen yang berfokus di panel.
    if (screen.queryByRole('dialog')) {
      await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Tutup' }));
    }
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    await waitFor(() => expect(tile).toHaveFocus());
  });

  it('Escape saat fokus di dalam panel menutupnya', async () => {
    const user = await mountTables();
    await user.click(await screen.findByRole('button', { name: /^T4, order WJM-0009/ }));
    const dialog = await screen.findByRole('dialog');
    await within(dialog).findByText('Kopi Susu');

    within(dialog).getByRole('button', { name: 'Tutup' }).focus();
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('ubin kosong tidak membuka apa pun dan tidak memanggil getTab', async () => {
    const user = await mountTables();
    const empty = await screen.findByTestId('board-tile-table-1');

    await user.click(empty);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(tabsApi.getTab).not.toHaveBeenCalled();
  });

  it('keyboard: Enter pada ubin terisi membuka detail', async () => {
    const user = await mountTables();
    const tile = await screen.findByRole('button', { name: /^T4, order WJM-0009/ });

    tile.focus();
    await user.keyboard('{Enter}');
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
  });

  it('order ternyata sudah dibayar: "Lihat transaksi" menutup panel dan membuka popup transaksi yang sudah ada', async () => {
    tabsApi.getTab.mockResolvedValue({ ...TAB, status: 'completed', paid_amount: '35000.00', balance: '0.00' });
    const user = await mountTables();

    await user.click(await screen.findByRole('button', { name: /^T4, order WJM-0009/ }));
    await user.click(await screen.findByRole('button', { name: 'Lihat transaksi' }));

    await waitFor(() => expect(getOrder).toHaveBeenCalledWith(9));
    expect(screen.queryByText('Order ini sudah dibayar dan tidak lagi terbuka.')).not.toBeInTheDocument();
  });

  it('kembali ke Daftar order saat panel pernah dibuka tidak membuka panel lagi di kunjungan Meja berikutnya', async () => {
    const user = await mountTables();
    await user.click(await screen.findByRole('button', { name: /^T4, order WJM-0009/ }));
    await screen.findByRole('dialog');
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Tutup' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

    await user.click(screen.getByRole('radio', { name: 'Daftar order' }));
    await user.click(await screen.findByRole('radio', { name: 'Meja' }));
    await screen.findByTestId('floor-board');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
