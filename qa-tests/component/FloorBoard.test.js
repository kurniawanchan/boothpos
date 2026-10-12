import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import { flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import FloorBoard from '../../resources/js/components/sales/FloorBoard.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { getFloor } from '../../resources/js/api/floor';
import { buildBoard } from '../../resources/js/utils/floorBoard';

vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));
vi.mock('../../resources/js/api/tabs', () => ({ getTab: vi.fn() }));

// 044-sales-table-view (US2) — papan Meja: empat angka, kelompok area, takeaway, dan semua keadaan
// (memuat / kosong / galat / usang). Jam dibekukan supaya "per HH.MM" dan lama terbuka deterministik.
const NOW = new Date(2026, 9, 11, 10, 5, 0); // waktu LOKAL, sama dengan cara papan memformat jam
const ago = (min) => new Date(NOW.getTime() - min * 60_000).toISOString();

const table = (id, over = {}) => ({ id, name: `T${id}`, seats: 4, is_active: true, state: 'free', tab: null, ...over });
const order = (id, over = {}) => ({
  id, order_number: `WJM-${String(id).padStart(4, '0')}`, total_amount: '30000.00', paid_amount: '0.00',
  opened_at: ago(19), session_id: 1, ready_count: 0, unsent_count: 0, ...over,
});
const busy = (id, tab) => table(id, { state: 'occupied', tab });

const SNAPSHOT = {
  areas: [
    { area: 'Indoor', tables: [table(1), busy(2, order(2)), busy(3, order(3, { paid_amount: '10000.00', opened_at: ago(51) }))] },
    { area: '', tables: [table(4)] },
  ],
  takeaway_tabs: [{ ...order(7, { total_amount: '15000.00' }), tab_label: 'Budi' }],
  version: 'v1',
};

let hidden = false;
const setHidden = (v) => { hidden = v; document.dispatchEvent(new Event('visibilitychange')); };

async function mountBoard({ menus = ['sales', 'floor'] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menus };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }, { path: '/setup', name: 'dining-setup', component: { template: '<div />' } }],
  });
  const view = render(FloorBoard, { global: { plugins: [pinia, router] } });
  await flushPromises();
  return view;
}
const tick = async (ms) => { await vi.advanceTimersByTimeAsync(ms); await flushPromises(); };
const figure = (label) => screen.getByText(label).nextElementSibling;
const tiles = () => document.querySelectorAll('[data-testid^="board-tile-"]');

describe('FloorBoard', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hidden = false;
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden });
    vi.useFakeTimers({ now: NOW });
    vi.spyOn(Math, 'random').mockReturnValue(0);
    getFloor.mockResolvedValue(SNAPSHOT);
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('empat angka sama dengan yang ditampilkan ubin dan dengan floorBoard.js', async () => {
    await mountBoard();

    const board = buildBoard(SNAPSHOT, NOW.getTime());
    expect(figure('Order terbuka')).toHaveTextContent(String(board.figures.openOrders)); // 3 meja terisi? 2 + 1 takeaway
    expect(board.figures.openOrders).toBe(3);
    expect(figure('Meja terisi')).toHaveTextContent('2 / 4');
    expect(screen.getByText('50% okupansi')).toBeInTheDocument();
    expect(figure('Meja > 45 menit')).toHaveTextContent('1'); // T3 (51 mnt); takeaway tidak dihitung
    expect(figure('Nilai order terbuka')).toHaveTextContent(/Rp\s*75\.000/);
    expect(screen.getByText('2 di meja · 1 takeaway')).toBeInTheDocument();

    // Angka = apa yang tampak di ubin.
    expect(document.querySelectorAll('[data-state="occupied"]')).toHaveLength(board.figures.occupiedTables + board.takeaway.length);
    expect(document.querySelectorAll('[data-over="true"]')).toHaveLength(board.figures.overLimit);
  });

  it('kelompok area berurutan seperti server, tanpa area di akhir, dengan "N dari M meja terisi"', async () => {
    await mountBoard();

    const regions = screen.getAllByRole('region').map((r) => r.getAttribute('aria-label'));
    expect(regions).toEqual(['Indoor', 'Tanpa area', 'Takeaway']);
    const indoor = screen.getByRole('region', { name: 'Indoor' });
    expect(within(indoor).getByText('2 dari 3 meja terisi')).toBeInTheDocument();
    expect(within(indoor).getAllByTestId(/^board-tile-/)).toHaveLength(3);
    expect(within(screen.getByRole('region', { name: 'Tanpa area' })).getByText('0 dari 1 meja terisi')).toBeInTheDocument();
  });

  it('grup takeaway hanya ada bila ada order takeaway, dan tidak ikut hitungan okupansi', async () => {
    getFloor.mockResolvedValue({ ...SNAPSHOT, takeaway_tabs: [] });
    await mountBoard();

    expect(screen.queryByRole('region', { name: 'Takeaway' })).not.toBeInTheDocument();
    expect(figure('Meja terisi')).toHaveTextContent('2 / 4');
    expect(figure('Order terbuka')).toHaveTextContent('2');
  });

  it('takeaway: ubin memakai label tab dan bisa dipilih', async () => {
    const { emitted } = await mountBoard();

    const group = screen.getByRole('region', { name: 'Takeaway' });
    const btn = within(group).getByRole('button', { name: /^Budi, order WJM-0007/ });
    btn.click();
    expect(emitted()['select-order'][0].slice(0, 2)).toEqual([7, btn]);
  });

  it('subjudul memuat jam pembaruan berhasil terakhir (HH.MM)', async () => {
    await mountBoard();
    expect(screen.getByText(/Live · per 10\.05 · klik meja terisi untuk detail order/)).toBeInTheDocument();
    expect(screen.getByText(/filter daftar order tidak berlaku/)).toBeInTheDocument();
  });

  it('legenda hanya memuat keadaan yang benar-benar ada di papan', async () => {
    await mountBoard();
    const legend = screen.getByTestId('board-legend');
    expect(within(legend).getByText('Kosong')).toBeInTheDocument();
    expect(within(legend).getByText('Terisi')).toBeInTheDocument();
    expect(within(legend).getByText('Sebagian dibayar')).toBeInTheDocument();
    expect(within(legend).getByText('> 45 menit')).toBeInTheDocument();
    expect(within(legend).queryByText('Meja nonaktif')).not.toBeInTheDocument();
  });

  it('legenda tanpa keadaan yang tak dipakai dan menampilkan "nonaktif" bila ada', async () => {
    getFloor.mockResolvedValue({
      areas: [{ area: 'A', tables: [busy(1, order(1)), table(2, { is_active: false, state: 'occupied', tab: order(2) })] }],
      takeaway_tabs: [], version: 'v1',
    });
    await mountBoard();
    const legend = screen.getByTestId('board-legend');
    expect(within(legend).queryByText('Kosong')).not.toBeInTheDocument();
    expect(within(legend).queryByText('Sebagian dibayar')).not.toBeInTheDocument();
    expect(within(legend).queryByText('> 45 menit')).not.toBeInTheDocument();
    expect(within(legend).getByText('Meja nonaktif')).toBeInTheDocument();
  });

  it('keadaan memuat: pesan memuat sampai data pertama tiba', async () => {
    getFloor.mockReturnValue(new Promise(() => {}));
    await mountBoard();
    expect(screen.getByText('Memuat papan meja…')).toBeInTheDocument();
    expect(tiles()).toHaveLength(0);
  });

  it('keadaan kosong: pesan + tautan setup HANYA untuk pengguna yang punya menu setup', async () => {
    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v0' });
    await mountBoard({ menus: ['sales', 'floor', 'dining_setup'] });
    expect(screen.getByText('Belum ada meja.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Buka Setup meja' })).toBeInTheDocument();
  });

  it('keadaan kosong tanpa menu setup: tidak ada tautan', async () => {
    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v0' });
    await mountBoard();
    expect(screen.getByText('Belum ada meja.')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Buka Setup meja' })).not.toBeInTheDocument();
  });

  it('galat saat memuat: pesan + Coba lagi, BUKAN lantai kosong; Coba lagi memulihkan', async () => {
    getFloor.mockRejectedValueOnce(new Error('net'));
    await mountBoard();
    expect(screen.getByRole('alert')).toHaveTextContent('Papan meja gagal dimuat.');
    expect(screen.queryByText('Belum ada meja.')).not.toBeInTheDocument();

    screen.getByRole('button', { name: 'Coba lagi' }).click();
    await flushPromises();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(tiles().length).toBeGreaterThan(0);
  });

  it('pembaruan gagal setelah sukses: data lama tetap tampil + pemberitahuan usang dengan jam sukses terakhir', async () => {
    await mountBoard();
    getFloor.mockRejectedValue(new Error('net'));
    await tick(3500);

    expect(screen.getByTestId('board-stale')).toHaveTextContent('Data terakhir 10.05. Pembaruan gagal, mencoba lagi…');
    expect(tiles().length).toBe(5);
    expect(screen.queryByText('Papan meja gagal dimuat.')).not.toBeInTheDocument();

    // Pulih: pemberitahuan hilang dan jam pembaruan ikut maju.
    getFloor.mockResolvedValue(SNAPSHOT);
    await tick(20000);
    expect(screen.queryByTestId('board-stale')).not.toBeInTheDocument();
  });

  it('respons `unchanged` tidak menggantikan ubin yang sudah ada', async () => {
    await mountBoard();
    const before = [...tiles()];
    getFloor.mockResolvedValue({ unchanged: true, version: 'v1' });
    await tick(7000);

    expect(getFloor.mock.calls.length).toBeGreaterThan(1);
    expect(getFloor).toHaveBeenLastCalledWith('v1');
    expect([...tiles()]).toEqual(before);
    before.forEach((el, i) => expect(tiles()[i]).toBe(el));
  });

  it('jam berdetak tanpa mengambil data baru: lama terbuka bertambah dan angka "> 45 menit" ikut berubah', async () => {
    getFloor.mockResolvedValue({
      areas: [{ area: 'A', tables: [busy(1, order(1, { opened_at: ago(44) }))] }], takeaway_tabs: [], version: 'v1',
    });
    await mountBoard();
    expect(screen.getByText('44 mnt')).toBeInTheDocument();
    expect(figure('Meja > 45 menit')).toHaveTextContent('0');

    getFloor.mockResolvedValue({ unchanged: true, version: 'v1' });
    await tick(120_000);
    expect(screen.getByText('46 mnt')).toBeInTheDocument();
    expect(figure('Meja > 45 menit')).toHaveTextContent('1');
  });

  it('polling berhenti saat halaman tersembunyi dan lanjut (penuh) saat tampil lagi', async () => {
    await mountBoard();
    const calls = getFloor.mock.calls.length;

    setHidden(true);
    await tick(30_000);
    expect(getFloor.mock.calls.length).toBe(calls);

    setHidden(false);
    await tick(0);
    expect(getFloor.mock.calls.length).toBe(calls + 1);
    expect(getFloor).toHaveBeenLastCalledWith(null); // pengambilan penuh: tanpa token versi
  });

  it('polling berhenti saat papan dilepas (pindah ke Daftar order)', async () => {
    const view = await mountBoard();
    view.unmount();
    const calls = getFloor.mock.calls.length;
    await tick(30_000);
    expect(getFloor.mock.calls.length).toBe(calls);
  });

  it('60 meja dirender semuanya', async () => {
    getFloor.mockResolvedValue({
      areas: [
        { area: 'Lt 1', tables: Array.from({ length: 30 }, (_, i) => (i % 3 === 0 ? busy(i + 1, order(i + 1)) : table(i + 1))) },
        { area: 'Lt 2', tables: Array.from({ length: 30 }, (_, i) => table(i + 100)) },
      ],
      takeaway_tabs: [], version: 'v1',
    });
    await mountBoard();

    expect(tiles()).toHaveLength(60);
    expect(figure('Meja terisi')).toHaveTextContent('10 / 60');
  });

  it('klik ubin terisi memancarkan select-order (id, elemen, ubin); ubin kosong tidak memancarkan apa pun', async () => {
    const { emitted } = await mountBoard();

    document.querySelector('[data-testid="board-tile-table-1"]').click();
    expect(emitted()['select-order']).toBeUndefined();

    const btn = screen.getByRole('button', { name: /^T2, order WJM-0002/ });
    btn.click();
    const [orderId, opener, tile] = emitted()['select-order'][0];
    expect(orderId).toBe(2);
    expect(opener).toBe(btn);
    expect(tile.name).toBe('T2');
  });

  it('meja nonaktif yang masih menahan order tetap tampil sebagai ubin terisi bertanda nonaktif', async () => {
    getFloor.mockResolvedValue({
      areas: [{ area: 'A', tables: [table(1, { is_active: false, state: 'occupied', tab: order(1) })] }], takeaway_tabs: [], version: 'v1',
    });
    await mountBoard();
    expect(screen.getByText('Nonaktif')).toBeInTheDocument();
    expect(figure('Meja terisi')).toHaveTextContent('1 / 1');
  });

  it('045: dengan beberapa outlet, area dikelompokkan per outlet dengan judul "Outlet — N dari M meja terisi"', async () => {
    getFloor.mockResolvedValue({
      outlets: [
        { id: 1, code: 'WJ1', name: 'Warkop 1', areas: [{ area: 'Indoor', tables: [table(1), busy(2, order(2))] }], takeaway_tabs: [] },
        { id: 2, code: 'WJ2', name: 'Warkop 2', areas: [{ area: 'Indoor', tables: [table(3), table(4), busy(5, order(5))] }], takeaway_tabs: [] },
      ],
      version: 'v1',
    });
    await mountBoard();

    const headings = screen.getAllByTestId('board-outlet-heading').map((h) => h.textContent.replace(/\s+/g, ' ').trim());
    expect(headings).toEqual(['Warkop 1 — 1 dari 2 meja terisi', 'Warkop 2 — 1 dari 3 meja terisi']);
    // Angka papan tetap satu: 2 terisi dari 5 meja, dihitung di floorBoard.js.
    expect(figure('Meja terisi')).toHaveTextContent('2 / 5');
  });

  it('045: satu outlet tidak memunculkan judul outlet', async () => {
    await mountBoard();
    expect(screen.queryAllByTestId('board-outlet-heading')).toHaveLength(0);
  });
});
