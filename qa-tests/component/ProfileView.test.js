import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProfileView from '../../resources/js/views/ProfileView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
  updateLanguage: vi.fn(),
  updatePassword: vi.fn(),
  updatePhoto: vi.fn(),
}));

import { updatePassword, updatePhoto } from '../../resources/js/api/auth';

function renderProfile() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = {
    id: 1,
    name: 'Kasir Satu',
    username: 'kasir01',
    role: 'cashier',
    menu_keys: ['dashboard', 'pos'],
    photo_url: null,
  };
  return { pinia, auth };
}

// 005-ux-enhancements-dashboard (US3)
describe('ProfileView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the logged-in user own details', async () => {
    const { pinia } = renderProfile();
    render(ProfileView, { global: { plugins: [pinia] } });

    expect(screen.getByText('Kasir Satu')).toBeInTheDocument();
    expect(screen.getByText('kasir01')).toBeInTheDocument();
    expect(screen.getByText('cashier')).toBeInTheDocument();
  });

  it('submits a password change and shows a success toast on the response message', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updatePassword.mockResolvedValue({ message: 'Password berhasil diubah.' });
    const { pinia } = renderProfile();
    render(ProfileView, { global: { plugins: [pinia] } });

    await user.type(screen.getByLabelText(/password saat ini/i), 'old-password-123');
    await user.type(screen.getByLabelText(/^password baru/i), 'new-password-456');
    await user.type(screen.getByLabelText(/konfirmasi password baru/i), 'new-password-456');
    await user.click(screen.getByRole('button', { name: /simpan password/i }));

    await waitFor(() =>
      expect(updatePassword).toHaveBeenCalledWith({
        current_password: 'old-password-123',
        password: 'new-password-456',
        password_confirmation: 'new-password-456',
      })
    );
  });

  it('shows a field error when the current password is rejected', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updatePassword.mockRejectedValue(
      new ApiError('Validasi gagal', { status: 422, errors: { current_password: ['Password saat ini salah.'] } })
    );
    const { pinia } = renderProfile();
    render(ProfileView, { global: { plugins: [pinia] } });

    await user.type(screen.getByLabelText(/password saat ini/i), 'wrong-password');
    await user.type(screen.getByLabelText(/^password baru/i), 'new-password-456');
    await user.type(screen.getByLabelText(/konfirmasi password baru/i), 'new-password-456');
    await user.click(screen.getByRole('button', { name: /simpan password/i }));

    await screen.findByText('Password saat ini salah.');
  });

  it('uploads a new photo and updates the auth store user', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updatePhoto.mockResolvedValue({ id: 1, name: 'Kasir Satu', username: 'kasir01', role: 'cashier', menu_keys: [], photo_url: 'https://example.test/p.jpg' });
    const { pinia, auth } = renderProfile();
    render(ProfileView, { global: { plugins: [pinia] } });

    const file = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    const input = document.querySelector('input[type="file"]');
    await user.upload(input, file);

    await waitFor(() => expect(updatePhoto).toHaveBeenCalledWith(file));
    await waitFor(() => expect(auth.user.photo_url).toBe('https://example.test/p.jpg'));
  });

  it('shows an error and leaves the photo unchanged when the upload is rejected', async () => {
    updatePhoto.mockRejectedValue(new ApiError('Tipe berkas tidak didukung.', { status: 422, errors: { image: ['Tipe berkas tidak didukung.'] } }));
    const { pinia, auth } = renderProfile();
    render(ProfileView, { global: { plugins: [pinia] } });

    // A real browser file picker would already filter by the input's
    // `accept` attribute — this exercises the server-side rejection path
    // (422 + field error) directly via fireEvent, since @testing-library's
    // upload() simulates that same accept-filtering and would otherwise
    // never let a non-image file through in this environment.
    const file = new File(['x'], 'doc.pdf', { type: 'application/pdf' });
    const input = document.querySelector('input[type="file"]');
    Object.defineProperty(input, 'files', { value: [file] });
    await fireEvent.change(input);

    await screen.findByText('Tipe berkas tidak didukung.');
    expect(auth.user.photo_url).toBeNull();
  });
});
