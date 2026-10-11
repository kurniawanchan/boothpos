import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createI18n } from 'vue-i18n';
import { createRouter, createMemoryHistory } from 'vue-router';
import SettingsView from '../../resources/js/views/SettingsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listSettings, updateSettings, uploadStoreLogo, featureFlags } from '../../resources/js/api/settings';
import { listPaymentChannels, deletePaymentChannel } from '../../resources/js/api/payments';
import { listBackups } from '../../resources/js/api/backups';
import id from '../../resources/js/locales/id.json';
import en from '../../resources/js/locales/en.json';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn(),
  listSettings: vi.fn(),
  updateSettings: vi.fn(),
  uploadStoreLogo: vi.fn(),
}));
vi.mock('../../resources/js/api/backups', () => ({
  listBackups: vi.fn(() => Promise.resolve({ data: [] })),
  createBackup: vi.fn(),
  downloadBackup: vi.fn(),
  restoreBackup: vi.fn(),
  restoreFromUpload: vi.fn(),
}));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn(),
  createPaymentChannel: vi.fn(),
  updatePaymentChannel: vi.fn(),
  deletePaymentChannel: vi.fn(),
}));

function imageFile(name = 'logo.png', type = 'image/png') {
  return new File(['fake-bytes'], name, { type });
}

// 041-back-office-ui-redesign: Pengaturan kini menampilkan SATU bagian per kali dan menyimpan bagian aktif di
// address bar (?section=), jadi view butuh router. Tes yang dulu menemukan semua kartu di satu halaman kini
// membuka bagian terkait lewat query yang sama dengan yang dipakai pengguna (tautan/refresh).
async function renderSettings(section) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'settings'] };
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id, en } });
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/settings', component: SettingsView }] });
  router.push(section ? { path: '/settings', query: { section } } : '/settings');
  await router.isReady();
  const view = render(SettingsView, { global: { plugins: [pinia, i18n, router] } });
  return { ...view, router };
}

describe('SettingsView — profil toko (US3)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 1, artist_limit_reached: false });
    listPaymentChannels.mockResolvedValue({ data: [] });
    listSettings.mockResolvedValue({
      data: [
        { key: 'store_name', value: 'Toko Saya', type: 'string', group: 'receipt' },
        { key: 'store_contact', value: '0812', type: 'string', group: 'receipt' },
        { key: 'store_address', value: 'Jl. Merdeka No. 1', type: 'string', group: 'receipt' },
        { key: 'store_contact_person', value: 'Budi', type: 'string', group: 'receipt' },
        { key: 'store_contact_phone', value: '0812-3456-7890', type: 'string', group: 'receipt' },
        { key: 'store_contact_email', value: 'toko@contoh.com', type: 'string', group: 'receipt' },
        { key: 'store_logo_path', value: 'store-logo/existing.png', type: 'string', group: 'receipt' },
      ],
      // 023-event-availability-invoice-redesign (US4, research.md Decision
      // 8) — URL sekarang diselesaikan BACKEND, bukan ditebak dari
      // store_logo_path di frontend.
      store_logo_url: 'http://localhost/storage/store-logo/existing.png',
    });
  });

  it('renders the persisted store-profile fields and current logo', async () => {
    await await renderSettings();

    expect(await screen.findByDisplayValue('Jl. Merdeka No. 1')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Budi')).toBeInTheDocument();
    expect(screen.getByDisplayValue('0812-3456-7890')).toBeInTheDocument();
    expect(screen.getByDisplayValue('toko@contoh.com')).toBeInTheDocument();
    expect(screen.getByAltText(/logo toko saat ini/i)).toHaveAttribute('src', 'http://localhost/storage/store-logo/existing.png');
  });

  it('saves the extended store-profile fields via the bulk PUT /settings call', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updateSettings.mockResolvedValue({ data: [] });
    await renderSettings();

    await screen.findByDisplayValue('Jl. Merdeka No. 1');
    await user.clear(screen.getByDisplayValue('toko@contoh.com'));
    await user.type(screen.getByLabelText(/^email$/i), 'baru@contoh.com');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(updateSettings).toHaveBeenCalled());
    const payload = updateSettings.mock.calls[0][0];
    expect(payload.find((s) => s.key === 'store_contact_email').value).toBe('baru@contoh.com');
  });

  it('rejects a non-image file client-side without calling the logo upload API', async () => {
    await renderSettings();
    await screen.findByDisplayValue('Jl. Merdeka No. 1');

    const input = screen.getByLabelText(/logo toko/i);
    await import('@testing-library/vue').then(({ fireEvent }) =>
      fireEvent.change(input, { target: { files: [new File(['x'], 'not-image.txt', { type: 'text/plain' })] } }),
    );

    expect(screen.getByText(/harus berupa gambar/i)).toBeInTheDocument();
    expect(uploadStoreLogo).not.toHaveBeenCalled();
  });

  it('uploads a valid logo file via POST /settings/store-logo', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    uploadStoreLogo.mockResolvedValue({
      data: { key: 'store_logo_path', value: 'store-logo/new.png' },
      store_logo_url: 'http://localhost/storage/store-logo/new.png',
    });
    await renderSettings();
    await screen.findByDisplayValue('Jl. Merdeka No. 1');

    const input = screen.getByLabelText(/logo toko/i);
    const file = imageFile();
    await import('@testing-library/vue').then(({ fireEvent }) => fireEvent.change(input, { target: { files: [file] } }));

    await user.click(screen.getByRole('button', { name: /unggah logo/i }));

    await waitFor(() => expect(uploadStoreLogo).toHaveBeenCalledWith(file));
  });
});

describe('SettingsView — mode DEMO/LIVE (003-seed-demo-live US2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listPaymentChannels.mockResolvedValue({ data: [] });
    listSettings.mockResolvedValue({ data: [] });
  });

  it('shows LIVE selected by default and asks for confirmation before switching to DEMO', async () => {
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 0, artist_limit_reached: false, system_mode: 'live' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderSettings('mode');

    expect(await screen.findByRole('heading', { name: 'Mode sistem' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'DEMO' }));

    expect(screen.getByText(/Pindah ke mode DEMO\?/)).toBeInTheDocument();
    expect(updateSettings).not.toHaveBeenCalled();
  });

  it('calls PUT /settings with the system_mode key once confirmed', async () => {
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 0, artist_limit_reached: false, system_mode: 'live' });
    updateSettings.mockResolvedValue({ data: [] });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderSettings('mode');

    expect(await screen.findByRole('heading', { name: 'Mode sistem' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'DEMO' }));
    await user.click(screen.getByRole('button', { name: 'Ya, lanjutkan' }));

    await waitFor(() => expect(updateSettings).toHaveBeenCalledWith([
      { key: 'system_mode', value: 'demo', type: 'string', group: 'system' },
    ]));
  });

  it('does not prompt when clicking the already-active mode', async () => {
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 0, artist_limit_reached: false, system_mode: 'live' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderSettings('mode');

    expect(await screen.findByRole('heading', { name: 'Mode sistem' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'LIVE' }));

    expect(screen.queryByText(/Pindah ke mode/)).not.toBeInTheDocument();
  });
});

describe('SettingsView — payment channel deletion (024 US6)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listPaymentChannels.mockResolvedValue({
      data: [{ id: 1, type: 'bank_transfer', provider: 'BCA', account_name: 'Toko', account_number: '1234567890', qr_image_url: null, is_active: true }],
    });
    listSettings.mockResolvedValue({
      data: [],
      store_logo_url: null,
    });
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 0, artist_limit_reached: false });
  });

  it('renders existing channels and allows deleting one with confirmation', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deletePaymentChannel.mockResolvedValue({});
    await renderSettings('channels');

    await screen.findByText('BCA');
    await user.click(screen.getByText('Hapus'));

    expect(await screen.findByText(/Hapus BCA/)).toBeInTheDocument();
    const confirmButtons = screen.getAllByRole('button', { name: 'Hapus' });
    await user.click(confirmButtons[confirmButtons.length - 1]);

    await waitFor(() => expect(deletePaymentChannel).toHaveBeenCalledWith(1));
  });

  it('shows an error toast when deletion fails', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const { useToastStore } = await import('../../resources/js/stores/toast');
    const user = userEvent.setup();
    deletePaymentChannel.mockRejectedValue(new Error('Cannot delete'));
    await renderSettings('channels');

    await screen.findByText('BCA');
    await user.click(screen.getByText('Hapus'));
    await screen.findByText(/Hapus BCA/);
    const confirmButtons2 = screen.getAllByRole('button', { name: 'Hapus' });
    await user.click(confirmButtons2[confirmButtons2.length - 1]);

    await waitFor(() => {
      expect(useToastStore().items.some((i) => i.message === 'Cannot delete')).toBe(true);
    });
  });
});

describe('SettingsView — nama aplikasi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 1, artist_limit_reached: false, app_name: 'BoothPOS' });
    listPaymentChannels.mockResolvedValue({ data: [] });
    listSettings.mockResolvedValue({ data: [], store_logo_url: null });
  });

  it('shows the current app name and saves a new one through the bulk settings call', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updateSettings.mockResolvedValue({ data: [] });
    await renderSettings('app');

    const input = await screen.findByLabelText('Nama aplikasi');
    expect(input).toHaveValue('BoothPOS');

    await user.clear(input);
    await user.type(input, 'Kasir Sakana');
    await user.click(screen.getByRole('button', { name: 'Simpan nama aplikasi' }));

    await waitFor(() =>
      expect(updateSettings).toHaveBeenCalledWith([{ key: 'app_name', value: 'Kasir Sakana', type: 'string', group: 'general' }]),
    );
  });

  it('caps the input at 50 characters, matching the server rule', async () => {
    await renderSettings('app');

    const input = await screen.findByLabelText('Nama aplikasi');

    expect(input).toHaveAttribute('maxlength', '50');
  });
});

describe('SettingsView — cadangan & pemulihan', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 1, artist_limit_reached: false, app_name: 'BoothPOS' });
    listPaymentChannels.mockResolvedValue({ data: [] });
    listSettings.mockResolvedValue({ data: [], store_logo_url: null });
    listBackups.mockResolvedValue({ data: [] });
  });

  it('offers the database backup & restore section on the settings screen', async () => {
    await renderSettings('backup');

    expect(await screen.findByText('Cadangan & pemulihan database')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Buat cadangan sekarang' })).toBeInTheDocument();
    await waitFor(() => expect(listBackups).toHaveBeenCalled());
  });
});

describe('SettingsView — struktur halaman (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    featureFlags.mockResolvedValue({ multi_artist_enabled: false, artist_count: 1, artist_limit_reached: false, app_name: 'BoothPOS' });
    listPaymentChannels.mockResolvedValue({ data: [] });
    listSettings.mockResolvedValue({ data: [], store_logo_url: null });
    listBackups.mockResolvedValue({ data: [] });
  });

  it('memakai PageHeader: satu h1 berisi judul dan deskripsi halaman', async () => {
    await renderSettings();

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('General');
    expect(screen.getByText('Lisensi, kanal pembayaran, identitas toko')).toBeInTheDocument();
  });

  it('menyediakan semua pengaturan lama sebagai bagian yang bisa dipilih', async () => {
    await renderSettings();

    const nav = screen.getByRole('navigation', { name: 'Bagian pengaturan' });
    for (const label of ['Identitas toko', 'Tampilan Struk', 'Kanal pembayaran', 'Nama aplikasi', 'Tema', 'Mode sistem', 'Tingkat lisensi', 'Cadangan & pemulihan']) {
      expect(within(nav).getByRole('button', { name: label })).toBeInTheDocument();
    }
  });

  it('bagian bawaan adalah identitas toko dan hanya satu bagian dirender', async () => {
    await renderSettings();

    expect(await screen.findByRole('heading', { level: 2, name: 'Identitas toko' })).toBeInTheDocument();
    expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(1);
    expect(screen.queryByRole('heading', { name: 'Mode sistem' })).not.toBeInTheDocument();
  });

  it('berpindah bagian dan menyalinnya ke address bar (?section=)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { router } = await renderSettings();

    await user.click(within(screen.getByRole('navigation', { name: 'Bagian pengaturan' })).getByRole('button', { name: 'Tema' }));

    expect(await screen.findByRole('heading', { level: 2, name: 'Tema' })).toBeInTheDocument();
    await waitFor(() => expect(router.currentRoute.value.query.section).toBe('theme'));
  });

  it('nilai ?section= yang tidak dikenal jatuh ke bagian pertama, bukan halaman kosong', async () => {
    await renderSettings('tidak-ada');

    expect(await screen.findByRole('heading', { level: 2, name: 'Identitas toko' })).toBeInTheDocument();
  });

  it('menandai bagian aktif dengan aria-current', async () => {
    await renderSettings('app');

    const nav = screen.getByRole('navigation', { name: 'Bagian pengaturan' });
    expect(within(nav).getByRole('button', { name: 'Nama aplikasi' })).toHaveAttribute('aria-current', 'page');
    expect(within(nav).getByRole('button', { name: 'Identitas toko' })).not.toHaveAttribute('aria-current');
  });

  it('mengganti tingkat lisensi lewat PUT /settings multi_artist_enabled', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updateSettings.mockResolvedValue({ data: [] });
    await renderSettings('tier');

    await screen.findByRole('heading', { level: 2, name: 'Tingkat lisensi' });
    await user.click(screen.getByRole('button', { name: /master/i }));

    await waitFor(() =>
      expect(updateSettings).toHaveBeenCalledWith([{ key: 'multi_artist_enabled', value: true, type: 'boolean', group: 'licensing' }]),
    );
  });
});
