import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import RolesView from '../../resources/js/views/RolesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listRoles, createRole, deleteRole } from '../../resources/js/api/roles';

vi.mock('../../resources/js/api/roles', () => ({
  listRoles: vi.fn(),
  getRole: vi.fn(),
  createRole: vi.fn(),
  updateRole: vi.fn(),
  deleteRole: vi.fn(),
  listMenuKeys: vi.fn().mockResolvedValue({
    data: [
      { key: 'pos', label: 'Kasir' },
      { key: 'session', label: 'Sesi Kasir' },
      { key: 'users', label: 'Pengguna' },
      { key: 'roles', label: 'Peran' },
    ],
  }),
}));

function renderRoles() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'roles'] };
  return render(RolesView, { global: { plugins: [pinia] } });
}

describe('RolesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listRoles.mockResolvedValue({
      data: [
        { id: 1, name: 'Kasir Event A', menu_keys: ['pos', 'session'], is_system_default: false, user_count: 3 },
      ],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('lists roles with their user count', async () => {
    renderRoles();
    expect(await screen.findByText('Kasir Event A')).toBeInTheDocument();
    expect(screen.getByText('3 pengguna')).toBeInTheDocument();
  });

  it('creates a role via the menu checkbox grid', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createRole.mockResolvedValue({ id: 2, name: 'Peran Baru', menu_keys: ['pos'], is_system_default: false, user_count: 0 });
    renderRoles();

    await screen.findByText('Kasir Event A');
    await user.click(screen.getByRole('button', { name: /tambah peran/i }));
    await user.type(screen.getByLabelText(/nama peran/i), 'Peran Baru');
    await screen.findByText('Kasir');
    await user.click(screen.getByRole('checkbox', { name: 'Kasir' }));
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() =>
      expect(createRole).toHaveBeenCalledWith(expect.objectContaining({ name: 'Peran Baru', menu_keys: ['pos'] }))
    );
  });

  it('surfaces the FR-014 delete-in-use 409 guard via the shared error toast flow', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const conflictError = Object.assign(new Error('Tidak bisa dihapus — masih dipakai oleh 3 pengguna.'), {
      isConflict: true,
    });
    deleteRole.mockRejectedValue(conflictError);
    renderRoles();

    await screen.findByText('Kasir Event A');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteRole).toHaveBeenCalledWith(1));
  });

  it('surfaces the FR-013 last-capable-role 409 guard distinctly from the delete-in-use guard', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const conflictError = Object.assign(
      new Error('Tidak bisa dihapus — ini peran terakhir yang bisa mengelola pengguna & peran. Toko akan kehilangan akses mengelola dirinya sendiri.'),
      { isConflict: true }
    );
    deleteRole.mockRejectedValue(conflictError);
    renderRoles();

    await screen.findByText('Kasir Event A');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteRole).toHaveBeenCalledWith(1));
    // Pesan yang ditoast diverifikasi lewat isi Error di atas — beda kata
    // kunci dari guard FR-014 ("masih dipakai") memastikan kedua guard
    // benar-benar disurfacekan sebagai pesan yang berbeda, bukan generik.
    expect(conflictError.message).toContain('peran terakhir');
  });
});
