import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ArtistsView from '../../resources/js/views/ArtistsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { listArtists, deleteArtist } from '../../resources/js/api/artists';
import { customHeaderCount } from '../../resources/js/composables/usePageHeader';

/**
 * 041-back-office-ui-redesign (US2) — ArtistsView memakai PageHeader (tindakan di kanan), FilterBar
 * (cari + "N data" + atur ulang) dan keadaan galat DataTable. Aturan kuota lisensi Pro tetap utuh.
 */
vi.mock('../../resources/js/api/artists', () => ({
  listArtists: vi.fn(),
  createArtist: vi.fn(),
  updateArtist: vi.fn(),
  deleteArtist: vi.fn(),
}));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn().mockResolvedValue({ multi_artist_enabled: true, artist_count: 1, artist_limit_reached: false }),
  updateSettings: vi.fn(),
}));

const ARTIST = { id: 1, code: 'ABC', name: 'Penjual Satu', contact_phone: '0812', contact_email: null, is_active: true, product_count: 3 };

function renderArtists({ menuKeys = ['dashboard', 'artists'] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  const settings = useSettingsStore();
  // load() di mounted menimpa nilai ini dengan jawaban featureFlags yang di-mock di atas.
  settings.multiArtistEnabled = true;
  return render(ArtistsView, { global: { plugins: [pinia] } });
}

describe('ArtistsView — struktur halaman (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    customHeaderCount.value = 0;
    listArtists.mockResolvedValue({ data: [ARTIST], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
  });

  it('PageHeader memuat judul, deskripsi, serta tombol ekspor/impor/tambah di kanan', async () => {
    renderArtists();
    expect(await screen.findByText('Penjual Satu')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'Penjual' })).toBeInTheDocument();
    expect(screen.getByText('Pemilik merchandise yang dititipkan')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /tambah penjual/i })).toBeEnabled();
    expect(screen.getByRole('button', { name: /ekspor/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /impor/i })).toBeInTheDocument();
    expect(customHeaderCount.value).toBe(1);
  });

  it('menyembunyikan ekspor/impor bagi peran tanpa menu penjual', async () => {
    renderArtists({ menuKeys: ['dashboard'] });
    await screen.findByText('Penjual Satu');
    expect(screen.queryByRole('button', { name: /ekspor/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /impor/i })).not.toBeInTheDocument();
  });

  it('penghitung "N data" mengikuti total server dan pencarian memanggil listArtists (debounce) dengan atur ulang', async () => {
    listArtists.mockResolvedValue({ data: [ARTIST], meta: { current_page: 1, per_page: 25, total: 42, last_page: 2 } });
    renderArtists();
    await screen.findByText('Penjual Satu');
    expect(screen.getByTestId('data-count')).toHaveTextContent('42 data');
    expect(screen.queryByRole('button', { name: /atur ulang/i })).not.toBeInTheDocument();

    await fireEvent.update(screen.getByRole('searchbox'), 'abc');
    await waitFor(() => expect(listArtists).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'abc', page: 1 })), { timeout: 2000 });

    await fireEvent.click(await screen.findByRole('button', { name: /atur ulang/i }));
    await waitFor(() => expect(listArtists).toHaveBeenLastCalledWith(expect.not.objectContaining({ search: 'abc' })));
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });

  it('tombol Tambah tetap nonaktif dengan banner penjelas saat kuota Pro habis (perilaku lama)', async () => {
    const { featureFlags } = await import('../../resources/js/api/settings');
    featureFlags.mockResolvedValueOnce({ multi_artist_enabled: false, artist_count: 1, artist_limit_reached: true });
    renderArtists();
    await screen.findByText('Penjual Satu');
    await waitFor(() => expect(screen.getByRole('button', { name: /tambah penjual/i })).toBeDisabled());
    expect(screen.getByText(/upgrade|master/i)).toBeInTheDocument();
  });

  it('menonaktifkan penjual lewat konfirmasi dan memanggil deleteArtist', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deleteArtist.mockResolvedValue({});
    renderArtists();
    await screen.findByText('Penjual Satu');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(await screen.findByRole('button', { name: /ya, nonaktifkan/i }));
    await waitFor(() => expect(deleteArtist).toHaveBeenCalledWith(1));
  });

  it('galat memuat menampilkan keadaan galat dengan tombol coba lagi', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listArtists.mockRejectedValueOnce(new Error('Server error'));
    renderArtists();
    expect(await screen.findByRole('alert')).toHaveTextContent('Server error');
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Penjual Satu')).toBeInTheDocument();
  });
});
