import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import TerminalsView from '../../resources/js/views/TerminalsView.vue';
import { useOutletStore } from '../../resources/js/stores/outlet';
import {
  listDevices,
  createDevice,
  updateDevice,
  deactivateDevice,
  reactivateDevice,
  deleteDevicePermanently,
} from '../../resources/js/api/devices';
import { visibleSections } from '../../resources/js/utils/navigation';

/**
 * 079 / US5 — halaman Terminal: daftar berfilter (outlet, status, nama) tanpa header bilah atas, tambah di outlet pilihan,
 * ubah/pindah dengan peringatan, nonaktif/aktifkan, hapus permanen. Aturan sebenarnya di server; di sini hanya
 * permintaan yang dikirim dan pesannya.
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
const B = { id: 2, code: 'WJ2', name: 'Warkop 2' };
const ROW_ACTIVE = { id: 10, name: 'Kasir Depan', outlet_id: 1, outlet: A, revoked: false, last_seen_at: '2026-10-27T03:00:00Z' };
const ROW_REVOKED = { id: 11, name: 'Kasir Lama', outlet_id: 2, outlet: B, revoked: true, last_seen_at: null };
const page = (rows) => ({ data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 } });

function renderView({ multi = true } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const outlets = useOutletStore();
  outlets.outlets = multi ? [A, B] : [A];
  outlets.multi = multi;
  outlets.selected = null;
  return render(TerminalsView, { global: { plugins: [pinia] } });
}

const lastParams = () => listDevices.mock.calls.at(-1)[0];

describe('TerminalsView (079)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDevices.mockResolvedValue(page([ROW_ACTIVE, ROW_REVOKED]));
  });

  it('memuat daftar tanpa header bilah atas dan menampilkan nama, outlet, status, terakhir terlihat', async () => {
    renderView();
    expect(await screen.findByText('Kasir Depan')).toBeInTheDocument();
    expect(listDevices).toHaveBeenCalledWith(expect.objectContaining({ page: 1 }), { skipOutletHeader: true });
    expect(screen.getByText('Belum pernah')).toBeInTheDocument();
    expect(screen.getByText('Nonaktif')).toBeInTheDocument();
    expect(screen.getByTestId('data-count')).toHaveTextContent('2 data');
  });

  it('filter status dan pencarian nama dikirim ke server dan digabung', async () => {
    renderView();
    await screen.findByText('Kasir Depan');

    await fireEvent.update(screen.getByRole('searchbox'), 'depan');
    await waitFor(() => expect(lastParams()).toMatchObject({ q: 'depan', page: 1 }), { timeout: 2000 });

    await fireEvent.click(await screen.findByRole('button', { name: /atur ulang/i }));
    await waitFor(() => expect(lastParams().q).toBeUndefined(), { timeout: 2000 });
  });

  it('pilih nonaktif sebagai status mengirim status=revoked', async () => {
    renderView();
    await screen.findByText('Kasir Depan');

    await fireEvent.click(screen.getByTestId('status-filter').querySelector('button') ?? screen.getByTestId('status-filter'));
    await fireEvent.click(await screen.findByRole('option', { name: 'Nonaktif' }));

    await waitFor(() => expect(lastParams()).toMatchObject({ status: 'revoked' }), { timeout: 2000 });
  });

  it('tambah terminal mengirim nama, dengan outlet pilihan lewat header (bukan body), lalu memuat ulang', async () => {
    createDevice.mockResolvedValue({ id: 99 });
    renderView();
    await screen.findByText('Kasir Depan');
    await fireEvent.click(screen.getByTestId('add-terminal'));

    const dialog = await screen.findByRole('dialog');
    await fireEvent.click(within(dialog).getByRole('combobox', { name: /outlet/i }));
    await fireEvent.click(await screen.findByRole('option', { name: 'Warkop 2' }));
    await fireEvent.update(within(dialog).getByLabelText(/nama terminal/i), 'Kasir 3');
    await fireEvent.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createDevice).toHaveBeenCalledWith({ name: 'Kasir 3' }, 2));
    await waitFor(() => expect(listDevices.mock.calls.length).toBeGreaterThan(1));
  });

  it('satu outlet: tidak ada pilihan outlet dan tidak ada outlet yang dikirim', async () => {
    createDevice.mockResolvedValue({ id: 99 });
    renderView({ multi: false });
    await screen.findByText('Kasir Depan');
    expect(screen.queryByTestId('outlet-filter')).not.toBeInTheDocument();
    await fireEvent.click(screen.getByTestId('add-terminal'));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByRole('combobox', { name: /outlet/i })).not.toBeInTheDocument();
    await fireEvent.update(within(dialog).getByLabelText(/nama terminal/i), 'Kasir 3');
    await fireEvent.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createDevice).toHaveBeenCalledWith({ name: 'Kasir 3' }, null));
  });

  it('galat 422 nama dari server tampil di bawah kolom nama', async () => {
    createDevice.mockRejectedValue(Object.assign(new Error('x'), { isValidation: true, errors: { name: ['Nama terminal "Kasir" sudah dipakai terminal lain di outlet ini.'] } }));
    renderView({ multi: false });
    await screen.findByText('Kasir Depan');
    await fireEvent.click(screen.getByTestId('add-terminal'));
    const dialog = await screen.findByRole('dialog');
    await fireEvent.update(within(dialog).getByLabelText(/nama terminal/i), 'Kasir');
    await fireEvent.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    expect(await screen.findByText(/sudah dipakai terminal lain/i)).toBeInTheDocument();
  });

  it('ubah hanya untuk terminal aktif; pindah outlet menampilkan peringatan tablet dan mengirim outlet_id', async () => {
    updateDevice.mockResolvedValue({});
    renderView();
    await screen.findByText('Kasir Depan');
    const edits = screen.getAllByRole('button', { name: /^edit$/i });
    expect(edits).toHaveLength(1);
    await fireEvent.click(edits[0]);

    const dialog = await screen.findByRole('dialog');
    expect(screen.queryByTestId('move-warning')).not.toBeInTheDocument();
    await fireEvent.click(within(dialog).getByRole('combobox', { name: /outlet/i }));
    await fireEvent.click(await screen.findByRole('option', { name: 'Warkop 2' }));
    expect(await screen.findByTestId('move-warning')).toHaveTextContent(/disiapkan ulang/i);
    await fireEvent.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(updateDevice).toHaveBeenCalledWith(10, { name: 'Kasir Depan', outlet_id: 2 }));
  });

  it('nonaktifkan dan hapus memakai dialog konfirmasi; aktifkan lagi langsung', async () => {
    deactivateDevice.mockResolvedValue();
    deleteDevicePermanently.mockResolvedValue();
    reactivateDevice.mockResolvedValue({});
    renderView();
    await screen.findByText('Kasir Depan');

    await fireEvent.click(screen.getByTestId('deactivate'));
    await fireEvent.click(await screen.findByRole('button', { name: /ya, nonaktifkan/i }));
    await waitFor(() => expect(deactivateDevice).toHaveBeenCalledWith(10));

    await fireEvent.click(screen.getByTestId('reactivate'));
    await waitFor(() => expect(reactivateDevice).toHaveBeenCalledWith(11));

    await fireEvent.click(screen.getAllByTestId('delete')[1]);
    await fireEvent.click(await screen.findByRole('button', { name: /ya, hapus/i }));
    await waitFor(() => expect(deleteDevicePermanently).toHaveBeenCalledWith(11));
  });

  it('kosong tanpa filter dan kosong dengan filter punya pesan berbeda', async () => {
    listDevices.mockResolvedValue(page([]));
    renderView();
    // Sebelum muatan pertama selesai tabel sempat menampilkan pesan kosong; tunggu sampai muatan selesai.
    await waitFor(() => expect(listDevices).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByText('Belum ada terminal.')).toBeInTheDocument());

    await fireEvent.update(screen.getByRole('searchbox'), 'zzz');
    await waitFor(() => expect(lastParams().q).toBe('zzz'), { timeout: 2000 });
    await waitFor(() => expect(screen.getByText('Tidak ada terminal yang cocok dengan filter.')).toBeInTheDocument());
  });
});

describe('navigasi Terminal (079)', () => {
  const names = (canAccess) => visibleSections(canAccess).flatMap((s) => s.items).flatMap((i) => i.children ?? [i]).map((i) => i.name);

  it('tampil untuk peran dengan menu settings dan disembunyikan sepenuhnya untuk peran lain', () => {
    expect(names((k) => k === 'settings')).toContain('terminals');
    expect(names((k) => ['dashboard', 'pos', 'session', 'outlets'].includes(k))).not.toContain('terminals');
  });
});
