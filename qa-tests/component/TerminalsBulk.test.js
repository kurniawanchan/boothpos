import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import TerminalsView from '../../resources/js/views/TerminalsView.vue';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { listDevices, bulkDevices } from '../../resources/js/api/devices';

/**
 * 079 / US5 (FR-025–FR-031) — aksi massal halaman Terminal: kotak pilih per baris dan untuk satu halaman, bilah dengan
 * Aktifkan/Nonaktifkan/Hapus, konfirmasi bermuatan jumlah, hasil per terminal (yang ditolak tetap terpilih dan disebut
 * dengan alasannya), dan pilihan dikosongkan saat filter atau halaman berubah. Aturan sebenarnya di server.
 */
vi.mock('../../resources/js/api/devices', () => ({
  listDevices: vi.fn(),
  createDevice: vi.fn(),
  updateDevice: vi.fn(),
  deactivateDevice: vi.fn(),
  reactivateDevice: vi.fn(),
  deleteDevicePermanently: vi.fn(),
  bulkDevices: vi.fn(),
}));

const A = { id: 1, code: 'WJ1', name: 'Warkop 1' };
const rows = [
  { id: 10, name: 'Kasir Depan', outlet_id: 1, outlet: A, revoked: false, last_seen_at: null },
  { id: 11, name: 'Kasir Lama', outlet_id: 1, outlet: A, revoked: true, last_seen_at: null },
  { id: 12, name: 'Dapur', outlet_id: 1, outlet: A, revoked: false, last_seen_at: null },
];
const page = (data) => ({ data, meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1 } });
const result = (data) => ({
  data,
  summary: ['changed', 'skipped', 'refused', 'not_found'].reduce((acc, k) => ({ ...acc, [k]: data.filter((r) => r.status === k).length }), {}),
});

function renderView() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const outlets = useOutletStore();
  outlets.outlets = [A];
  outlets.multi = false;
  return render(TerminalsView, { global: { plugins: [pinia] } });
}

const tick = (name) => screen.getByRole('checkbox', { name });

describe('TerminalsView — aksi massal (079)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDevices.mockResolvedValue(page(rows));
  });

  it('bilah tersembunyi tanpa pilihan; muncul dengan jumlah dipilih; kotak kepala memilih satu halaman', async () => {
    renderView();
    await screen.findByText('Kasir Depan');
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();

    await fireEvent.click(tick('Pilih Kasir Depan'));
    const bar = await screen.findByRole('toolbar');
    expect(within(bar).getByText('1 dipilih')).toBeInTheDocument();
    for (const name of ['Aktifkan', 'Nonaktifkan', 'Hapus']) expect(within(bar).getByRole('button', { name })).toBeInTheDocument();

    await fireEvent.click(screen.getAllByRole('checkbox')[0]);
    expect(within(await screen.findByRole('toolbar')).getByText('3 dipilih')).toBeInTheDocument();
  });

  it('aktifkan langsung tanpa konfirmasi dan mengirim aksi serta id terpilih', async () => {
    bulkDevices.mockResolvedValue(result([{ id: 11, name: 'Kasir Lama', status: 'changed', reason: null }]));
    renderView();
    await screen.findByText('Kasir Depan');
    await fireEvent.click(tick('Pilih Kasir Lama'));
    await fireEvent.click(within(screen.getByRole('toolbar')).getByRole('button', { name: 'Aktifkan' }));

    await waitFor(() => expect(bulkDevices).toHaveBeenCalledWith('activate', [11]));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await waitFor(() => expect(listDevices).toHaveBeenCalledTimes(2)); // daftar dimuat ulang
  });

  it('nonaktifkan dan hapus meminta konfirmasi yang menyebut jumlah; batal tidak mengirim apa pun', async () => {
    renderView();
    await screen.findByText('Kasir Depan');
    await fireEvent.click(tick('Pilih Kasir Depan'));
    await fireEvent.click(tick('Pilih Dapur'));

    await fireEvent.click(within(screen.getByRole('toolbar')).getByRole('button', { name: 'Hapus' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/2 terminal/)).toBeInTheDocument();
    await fireEvent.click(within(dialog).getByRole('button', { name: /batal/i }));
    expect(bulkDevices).not.toHaveBeenCalled();

    bulkDevices.mockResolvedValue(result([
      { id: 10, name: 'Kasir Depan', status: 'changed', reason: null },
      { id: 12, name: 'Dapur', status: 'changed', reason: null },
    ]));
    await fireEvent.click(within(screen.getByRole('toolbar')).getByRole('button', { name: 'Nonaktifkan' }));
    await fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Ya, nonaktifkan' }));
    await waitFor(() => expect(bulkDevices).toHaveBeenCalledWith('deactivate', [10, 12]));
  });

  it('hasil campuran: yang berhasil berubah, yang ditolak tetap terpilih dan disebut dengan alasannya', async () => {
    bulkDevices.mockResolvedValue(result([
      { id: 10, name: 'Kasir Depan', status: 'changed', reason: null },
      { id: 12, name: 'Dapur', status: 'refused', reason: 'Terminal ini sudah pernah dipakai' },
    ]));
    renderView();
    await screen.findByText('Kasir Depan');
    await fireEvent.click(tick('Pilih Kasir Depan'));
    await fireEvent.click(tick('Pilih Dapur'));
    await fireEvent.click(within(screen.getByRole('toolbar')).getByRole('button', { name: 'Hapus' }));
    await fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Ya, hapus' }));

    const bar = await screen.findByRole('toolbar');
    await waitFor(() => expect(within(bar).getByText(/Terminal ini sudah pernah dipakai/)).toBeInTheDocument());
    expect(within(bar).getByText('Dapur')).toBeInTheDocument();
    expect(within(bar).getByText('1 dipilih')).toBeInTheDocument();
    expect(tick('Pilih Dapur')).toBeChecked();
    expect(tick('Pilih Kasir Depan')).not.toBeChecked();
  });

  it('mengubah filter mengosongkan pilihan', async () => {
    renderView();
    await screen.findByText('Kasir Depan');
    await fireEvent.click(tick('Pilih Kasir Depan'));
    expect(await screen.findByRole('toolbar')).toBeInTheDocument();

    await fireEvent.update(screen.getByRole('searchbox'), 'dapur');
    await waitFor(() => expect(screen.queryByRole('toolbar')).not.toBeInTheDocument(), { timeout: 2000 });
  });
});
