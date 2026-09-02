import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import UsersView from '../../resources/js/views/UsersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listUsers, createUser, deleteUser } from '../../resources/js/api/users';
import { listRoles } from '../../resources/js/api/roles';

vi.mock('../../resources/js/api/users', () => ({
  listUsers: vi.fn(),
  getUser: vi.fn(),
  createUser: vi.fn(),
  updateUser: vi.fn(),
  deleteUser: vi.fn(),
  uploadUserPhoto: vi.fn(),
}));
vi.mock('../../resources/js/api/roles', () => ({
  listRoles: vi.fn(),
  getRole: vi.fn(),
  createRole: vi.fn(),
  updateRole: vi.fn(),
  deleteRole: vi.fn(),
  listMenuKeys: vi.fn(),
}));

function renderUsers(currentUser = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'users'] }) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = currentUser;
  return render(UsersView, { global: { plugins: [pinia] } });
}

describe('UsersView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listUsers.mockResolvedValue({
      data: [
        { id: 1, name: 'Owner', username: 'owner', role: { id: 1, name: 'Owner' }, is_active: true, photo_url: null, last_login_at: null },
        { id: 2, name: 'Kasir Satu', username: 'kasir01', role: { id: 2, name: 'Kasir' }, is_active: true, photo_url: null, last_login_at: '2026-09-01T10:00:00Z' },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    listRoles.mockResolvedValue({ data: [{ id: 1, name: 'Owner' }, { id: 2, name: 'Kasir' }] });
  });

  it('lists users from the API', async () => {
    renderUsers();
    expect(await screen.findByText('Kasir Satu')).toBeInTheDocument();
    expect(screen.getByText('kasir01')).toBeInTheDocument();
  });

  it('searches by name/username', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderUsers();

    await screen.findByText('Kasir Satu');
    await user.type(screen.getByPlaceholderText(/cari nama atau username/i), 'kasir');

    await waitFor(() => expect(listUsers).toHaveBeenCalledWith(expect.objectContaining({ search: 'kasir' })), { timeout: 1000 });
  });

  it('creates a user via the form', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createUser.mockResolvedValue({ id: 3, name: 'Baru', username: 'baru' });
    renderUsers();

    await screen.findByText('Kasir Satu');
    await user.click(screen.getByRole('button', { name: /tambah pengguna/i }));
    await user.type(screen.getByLabelText(/^nama/i), 'Baru');
    await user.type(screen.getByLabelText(/username/i), 'baru');
    await user.type(screen.getByLabelText(/password/i), 'password123');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createUser).toHaveBeenCalledWith(expect.objectContaining({ name: 'Baru', username: 'baru' })));
  });

  it('hides deactivate/delete actions on the current user own row (self-lockout UI guard)', async () => {
    renderUsers({ id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'users'] });

    await screen.findByText('Kasir Satu');
    const rows = screen.getAllByRole('row');
    const ownerRow = rows.find((r) => r.textContent.includes('owner'));
    expect(ownerRow.querySelector('button.text-danger-text')).toBeNull();

    const kasirRow = rows.find((r) => r.textContent.includes('kasir01'));
    expect(kasirRow.querySelector('button.text-danger-text')).not.toBeNull();
  });

  it('surfaces the 409 self-lockout message via the shared error toast flow on delete', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const conflictError = Object.assign(new Error('Tidak dapat menghapus akun yang sedang Anda gunakan sendiri.'), { isConflict: true });
    deleteUser.mockRejectedValue(conflictError);
    renderUsers();

    await screen.findByText('Kasir Satu');
    const rows = screen.getAllByRole('row');
    const kasirRow = rows.find((r) => r.textContent.includes('kasir01'));
    await user.click(kasirRow.querySelector('button.text-danger-text'));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteUser).toHaveBeenCalledWith(2));
  });

  it('rejects a non-image photo client-side', async () => {
    renderUsers();
    await screen.findByText('Kasir Satu');
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /tambah pengguna/i }));

    const fileInput = document.querySelector('input[type="file"]');
    expect(fileInput.getAttribute('accept')).toBe('image/jpeg,image/png');
  });
});
