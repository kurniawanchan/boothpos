import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SettingsView from '../../resources/js/views/SettingsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listSettings, updateSettings, uploadStoreLogo, featureFlags } from '../../resources/js/api/settings';
import { listPaymentChannels } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn(),
  listSettings: vi.fn(),
  updateSettings: vi.fn(),
  uploadStoreLogo: vi.fn(),
}));
vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn(),
  createPaymentChannel: vi.fn(),
  updatePaymentChannel: vi.fn(),
}));

function imageFile(name = 'logo.png', type = 'image/png') {
  return new File(['fake-bytes'], name, { type });
}

function renderSettings() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'settings'] };
  return render(SettingsView, { global: { plugins: [pinia] } });
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
    });
  });

  it('renders the persisted store-profile fields and current logo', async () => {
    renderSettings();

    expect(await screen.findByDisplayValue('Jl. Merdeka No. 1')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Budi')).toBeInTheDocument();
    expect(screen.getByDisplayValue('0812-3456-7890')).toBeInTheDocument();
    expect(screen.getByDisplayValue('toko@contoh.com')).toBeInTheDocument();
    expect(screen.getByAltText(/logo toko saat ini/i)).toHaveAttribute('src', '/storage/store-logo/existing.png');
  });

  it('saves the extended store-profile fields via the bulk PUT /settings call', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updateSettings.mockResolvedValue({ data: [] });
    renderSettings();

    await screen.findByDisplayValue('Jl. Merdeka No. 1');
    await user.clear(screen.getByDisplayValue('toko@contoh.com'));
    await user.type(screen.getByLabelText(/^email$/i), 'baru@contoh.com');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(updateSettings).toHaveBeenCalled());
    const payload = updateSettings.mock.calls[0][0];
    expect(payload.find((s) => s.key === 'store_contact_email').value).toBe('baru@contoh.com');
  });

  it('rejects a non-image file client-side without calling the logo upload API', async () => {
    renderSettings();
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
    uploadStoreLogo.mockResolvedValue({ data: { key: 'store_logo_path', value: 'store-logo/new.png' } });
    renderSettings();
    await screen.findByDisplayValue('Jl. Merdeka No. 1');

    const input = screen.getByLabelText(/logo toko/i);
    const file = imageFile();
    await import('@testing-library/vue').then(({ fireEvent }) => fireEvent.change(input, { target: { files: [file] } }));

    await user.click(screen.getByRole('button', { name: /unggah logo/i }));

    await waitFor(() => expect(uploadStoreLogo).toHaveBeenCalledWith(file));
  });
});
