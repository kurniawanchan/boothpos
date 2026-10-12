import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { flushPromises } from '@vue/test-utils';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import OpenOrderDetail from '../../resources/js/components/sales/OpenOrderDetail.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import * as tabsApi from '../../resources/js/api/tabs';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/tabs', () => ({
  getTab: vi.fn(),
  // Semua penulis tab dipantau: detail ini HANYA-BACA dan tak boleh memanggil satu pun.
  openTab: vi.fn(), closeEmptyTab: vi.fn(), addTabLine: vi.fn(), updateTabLine: vi.fn(), removeTabLine: vi.fn(),
  voidTab: vi.fn(), payTab: vi.fn(), sendTab: vi.fn(), moveTab: vi.fn(), mergeTab: vi.fn(), splitTab: vi.fn(),
}));

// 044-sales-table-view (US3) — panel detail order terbuka: hanya-baca, hidup (polling ±5 dtk), dan menangani
// akhir hidup order (dibayar / dibatalkan / gagal memperbarui).
const NOW = new Date(2026, 9, 11, 10, 5, 0);
const ago = (min) => new Date(NOW.getTime() - min * 60_000).toISOString();

const TAB = {
  id: 9, order_number: 'WJM-0009', status: 'open', service_type: 'dine_in',
  table: { id: 4, name: 'T4', area: 'Indoor' }, tab_label: null, opened_by: 'Rina', session_id: 1, customer_id: null,
  subtotal: '40000.00', discount_amount: '5000.00', total_amount: '35000.00', paid_amount: '10000.00', change_amount: '0.00', balance: '25000.00',
  created_at: ago(19),
  lines: [
    { id: 1, variant_id: 5, name: 'Kopi Susu', qty: 2, unit_price: '15000.00', line_total: '30000.00', modifiers: [{ group: 'Gula', option: 'Less sugar', price_delta: '0.00' }], note: 'tanpa es', sent: true, prep_status: 'ready', ticket_item_id: 11, station: { id: 1, name: 'Bar' }, cost_price: '9999.00' },
    { id: 2, variant_id: 6, name: 'Roti Bakar', qty: 1, unit_price: '10000.00', line_total: '10000.00', modifiers: [], note: null, sent: false, prep_status: null, ticket_item_id: null, station: null },
  ],
  payments: [{ id: 1, method: 'cash', amount: '10000.00', verification: 'verified', paid_at: ago(5) }],
};
const withTab = (over) => ({ ...TAB, ...over });

const MUTATORS = ['openTab', 'closeEmptyTab', 'addTabLine', 'updateTabLine', 'removeTabLine', 'voidTab', 'payTab', 'sendTab', 'moveTab', 'mergeTab', 'splitTab'];

let hidden = false;
const setHidden = (v) => { hidden = v; document.dispatchEvent(new Event('visibilitychange')); };
const tick = async (ms) => { await vi.advanceTimersByTimeAsync(ms); await flushPromises(); };

async function mountDetail({ props = {}, menus = ['sales', 'floor'] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menus };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/floor/tabs/:id', name: 'floor-tab', component: { template: '<div />' } }],
  });
  const view = render(OpenOrderDetail, { props: { orderId: 9, ...props }, global: { plugins: [pinia, router] } });
  await flushPromises();
  return view;
}

describe('OpenOrderDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hidden = false;
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    vi.useFakeTimers({ now: NOW });
    vi.spyOn(Math, 'random').mockReturnValue(0);
    tabsApi.getTab.mockResolvedValue(TAB);
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('menampilkan kicker meja, nomor order, fakta, baris, total, dan pembayaran', async () => {
    await mountDetail();

    expect(tabsApi.getTab).toHaveBeenCalledWith(9);
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Meja T4')).toBeInTheDocument();
    expect(within(dialog).getByRole('heading', { name: 'WJM-0009' })).toBeInTheDocument();
    expect(within(dialog).getByText('Terbuka')).toBeInTheDocument();

    // fakta
    expect(within(dialog).getByText('Dibuka oleh').nextElementSibling).toHaveTextContent('Rina');
    expect(within(dialog).getByText('Lama terbuka').nextElementSibling).toHaveTextContent('19 mnt');
    expect(within(dialog).getByText('Meja / area').nextElementSibling).toHaveTextContent('T4 · Indoor');
    expect(within(dialog).getAllByText('Dapur')[0].nextElementSibling).toHaveTextContent('1 siap · 1 belum dikirim');
    expect(within(dialog).getByText('Dibuka pada').nextElementSibling).not.toHaveTextContent('—');

    // baris: qty, nama, modifier, catatan, jumlah, status dapur
    const lines = within(screen.getByTestId('detail-lines'));
    expect(lines.getByText('Kopi Susu')).toBeInTheDocument();
    expect(lines.getByText('Less sugar')).toBeInTheDocument();
    expect(lines.getByText(/tanpa es/)).toBeInTheDocument();
    expect(lines.getByText(/30\.000/)).toBeInTheDocument();
    expect(lines.getByText(/Siap saji · Bar/)).toBeInTheDocument();
    expect(lines.getByText('Roti Bakar')).toBeInTheDocument();
    expect(lines.getByText('Belum dikirim')).toBeInTheDocument();

    // total
    const totals = within(screen.getByTestId('detail-totals'));
    expect(totals.getByText('Subtotal').nextElementSibling).toHaveTextContent(/Rp\s*40\.000/);
    expect(totals.getByText('Diskon').nextElementSibling).toHaveTextContent(/Rp\s*5\.000/);
    expect(totals.getByText('Total').nextElementSibling).toHaveTextContent(/Rp\s*35\.000/);
    expect(totals.getByText('Dibayar').nextElementSibling).toHaveTextContent(/Rp\s*10\.000/);
    expect(totals.getByText('Sisa').nextElementSibling).toHaveTextContent(/Rp\s*25\.000/);

    // pembayaran
    const pays = within(screen.getByTestId('detail-payments'));
    expect(pays.getByText(/Tunai/i)).toBeInTheDocument();
    expect(pays.getByText(/10\.000/)).toBeInTheDocument();
  });

  it('order sebagian dibayar memakai chip "Sebagian dibayar"; lewat 45 menit memakai chip "> 45 menit"', async () => {
    tabsApi.getTab.mockResolvedValue(withTab({ created_at: ago(52) }));
    await mountDetail();

    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Sebagian dibayar')).toBeInTheDocument();
    expect(within(dialog).getByText('> 45 menit')).toBeInTheDocument();
    expect(within(dialog).getByText('Lama terbuka').nextElementSibling).toHaveTextContent('52 mnt');
  });

  it('order tanpa pembayaran: tanpa chip sebagian dan "Belum ada pembayaran."', async () => {
    tabsApi.getTab.mockResolvedValue(withTab({ paid_amount: '0.00', balance: '35000.00', payments: [] }));
    await mountDetail();

    expect(screen.queryByText('Sebagian dibayar')).not.toBeInTheDocument();
    expect(screen.getByText('Belum ada pembayaran.')).toBeInTheDocument();
  });

  it('order takeaway memakai kicker "Takeaway"', async () => {
    tabsApi.getTab.mockResolvedValue(withTab({ table: null, tab_label: 'Budi' }));
    await mountDetail();

    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Takeaway')).toBeInTheDocument();
    expect(within(dialog).getByText('Meja / area').nextElementSibling).toHaveTextContent('Budi');
  });

  it('STRICTLY READ-ONLY: tidak ada kendali yang mengubah order dan tidak ada panggilan penulis API', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    await mountDetail();

    const names = screen.getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent.trim());
    expect(names.join('|')).not.toMatch(/tambah|kirim ke dapur|bayar|pindah|gabung|pisah|batalkan|hapus|void|diskon|simpan/i);
    expect(screen.queryAllByRole('textbox')).toHaveLength(0);
    expect(screen.queryAllByRole('spinbutton')).toHaveLength(0);

    // Klik semua kontrol yang ada tidak boleh memanggil penulis mana pun.
    for (const b of screen.getAllByRole('button')) await user.click(b);
    await tick(10_000);
    for (const name of MUTATORS) expect(tabsApi[name], name).not.toHaveBeenCalled();
  });

  it('tidak menampilkan field biaya yang kebetulan ikut di payload', async () => {
    await mountDetail();
    expect(screen.queryByText(/9\.999/)).not.toBeInTheDocument();
  });

  it('menyegarkan isi tiap ±5 detik: perubahan baris muncul tanpa tutup-buka', async () => {
    await mountDetail();
    expect(screen.queryByText('Es Teh')).not.toBeInTheDocument();
    const calls = tabsApi.getTab.mock.calls.length;

    tabsApi.getTab.mockResolvedValue(withTab({
      lines: [...TAB.lines, { id: 3, name: 'Es Teh', qty: 1, unit_price: '8000.00', line_total: '8000.00', modifiers: [], note: null, sent: false, prep_status: null, station: null }],
      subtotal: '48000.00', total_amount: '43000.00', balance: '33000.00',
    }));
    await tick(5500);

    expect(tabsApi.getTab.mock.calls.length).toBeGreaterThan(calls);
    expect(screen.getByText('Es Teh')).toBeInTheDocument();
    expect(within(screen.getByTestId('detail-totals')).getByText('Total').nextElementSibling).toHaveTextContent(/43\.000/);
  });

  it('status completed: pesan "sudah dibayar" + tombol Lihat transaksi yang memancarkan id order; polling berhenti', async () => {
    const { emitted } = await mountDetail();
    tabsApi.getTab.mockResolvedValue(withTab({ status: 'completed', paid_amount: '35000.00', balance: '0.00' }));
    await tick(5500);

    expect(screen.getByText('Order ini sudah dibayar dan tidak lagi terbuka.')).toBeInTheDocument();
    expect(screen.queryByText('Terbuka')).not.toBeInTheDocument(); // chip "Terbuka" hanya untuk order yang masih terbuka
    screen.getByRole('button', { name: 'Lihat transaksi' }).click();
    expect(emitted()['view-transaction']).toEqual([[9]]);

    const calls = tabsApi.getTab.mock.calls.length;
    await tick(30_000);
    expect(tabsApi.getTab.mock.calls.length).toBe(calls);
  });

  it('404 (dibatalkan/digabung): pesan khusus, tanpa tombol Lihat transaksi; polling berhenti', async () => {
    await mountDetail();
    tabsApi.getTab.mockRejectedValue(new ApiError('nf', { status: 404 }));
    await tick(5500);

    expect(screen.getByText('Order ini sudah dibatalkan atau digabung dan tidak lagi terbuka.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Lihat transaksi' })).not.toBeInTheDocument();
    const calls = tabsApi.getTab.mock.calls.length;
    await tick(30_000);
    expect(tabsApi.getTab.mock.calls.length).toBe(calls);
  });

  it('pembaruan gagal setelah sukses: data lama tetap + tanda usang; pulih otomatis', async () => {
    await mountDetail();
    tabsApi.getTab.mockRejectedValue(new ApiError('net', { status: 500 }));
    await tick(5500);

    expect(screen.getByRole('status')).toHaveTextContent('Data mungkin usang — pembaruan gagal.');
    expect(screen.getByText('Kopi Susu')).toBeInTheDocument();

    tabsApi.getTab.mockResolvedValue(TAB);
    await tick(20_000);
    expect(screen.queryByText('Data mungkin usang — pembaruan gagal.')).not.toBeInTheDocument();
  });

  it('gagal pada pemuatan pertama: pesan galat + Coba lagi, bukan panel kosong; Coba lagi memulihkan', async () => {
    tabsApi.getTab.mockRejectedValueOnce(new ApiError('net', { status: 500 }));
    await mountDetail();

    expect(screen.getByRole('alert')).toHaveTextContent('Order gagal dimuat.');
    expect(screen.queryByTestId('detail-lines')).not.toBeInTheDocument();

    screen.getByRole('button', { name: 'Coba lagi' }).click();
    await flushPromises();
    expect(screen.getByText('Kopi Susu')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('tautan "Buka di layar kasir" menuju floor-tab dan hanya ada dengan akses lantai', async () => {
    await mountDetail();
    expect(screen.getByRole('link', { name: 'Buka di layar kasir' })).toHaveAttribute('href', '/floor/tabs/9');
  });

  it('tanpa akses lantai tautan kasir tidak dirender', async () => {
    await mountDetail({ menus: ['sales'] });
    expect(screen.queryByRole('link', { name: 'Buka di layar kasir' })).not.toBeInTheDocument();
  });

  it('Escape menutup (memancarkan close)', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    const { emitted } = await mountDetail();

    // Escape didengar BaseDrawer pada panel (fokus ada di dalamnya setelah dibuka).
    within(screen.getByRole('dialog')).getByRole('button', { name: 'Tutup' }).focus();
    await user.keyboard('{Escape}');
    expect(emitted().close?.length).toBeGreaterThan(0);
  });

  it('polling berhenti saat panel ditutup (orderId null) dan tidak memanggil API', async () => {
    const view = await mountDetail();
    await view.rerender({ orderId: null });
    const calls = tabsApi.getTab.mock.calls.length;
    await tick(30_000);

    expect(tabsApi.getTab.mock.calls.length).toBe(calls);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('polling berhenti saat halaman tersembunyi', async () => {
    await mountDetail();
    const calls = tabsApi.getTab.mock.calls.length;

    setHidden(true);
    await tick(30_000);
    expect(tabsApi.getTab.mock.calls.length).toBe(calls);
    setHidden(false);
    await tick(0);
    expect(tabsApi.getTab.mock.calls.length).toBe(calls + 1);
  });

  it('berganti ke order lain mengosongkan data order sebelumnya', async () => {
    const view = await mountDetail();
    tabsApi.getTab.mockResolvedValue(withTab({ id: 12, order_number: 'WJM-0012', lines: [{ id: 8, name: 'Nasi Goreng', qty: 1, unit_price: '20000.00', line_total: '20000.00', modifiers: [], note: null, sent: false, prep_status: null, station: null }] }));
    await view.rerender({ orderId: 12 });
    await flushPromises();

    expect(tabsApi.getTab).toHaveBeenLastCalledWith(12);
    expect(screen.getByText('Nasi Goreng')).toBeInTheDocument();
    expect(screen.queryByText('Kopi Susu')).not.toBeInTheDocument();
  });
});
