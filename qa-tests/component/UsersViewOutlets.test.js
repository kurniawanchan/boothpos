import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import UsersView from '../../resources/js/views/UsersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { listUsers, updateUser, setUserOutlets } from '../../resources/js/api/users';
import { listRoles } from '../../resources/js/api/roles';

// 045 Stage 2 (T042) — penugasan outlet di formulir pengguna: hanya bila ada lebih dari satu outlet dan perannya
// bukan Owner/Admin; disimpan lewat PUT /users/{id}/outlets setelah pengguna tersimpan.
vi.mock('../../resources/js/api/users', () => ({
  listUsers: vi.fn(), getUser: vi.fn(), createUser: vi.fn(), updateUser: vi.fn(), deleteUser: vi.fn(),
  uploadUserPhoto: vi.fn(), setUserOutlets: vi.fn(),
}));
vi.mock('../../resources/js/api/roles', () => ({
  listRoles: vi.fn(), getRole: vi.fn(), createRole: vi.fn(), updateRole: vi.fn(), deleteRole: vi.fn(), listMenuKeys: vi.fn(),
}));

const TWO = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];

function renderUsers(outlets) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'users'] };
  const store = useOutletStore();
  store.outlets = outlets;
  store.multi = outlets.length > 1;
  return render(UsersView, { global: { plugins: [pinia] } });
}

describe('UsersView outlet assignment (045 Stage 2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listUsers.mockResolvedValue({
      data: [
        { id: 1, name: 'Owner', username: 'owner', role: { id: 1, name: 'Owner' }, is_active: true, photo_url: null, last_login_at: null, outlet_ids: [], all_outlets: true },
        { id: 2, name: 'Kasir Satu', username: 'kasir01', role: { id: 2, name: 'Kasir' }, is_active: true, photo_url: null, last_login_at: null, outlet_ids: [1] },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    listRoles.mockResolvedValue({ data: [{ id: 1, name: 'Owner', all_outlets: true }, { id: 2, name: 'Kasir', all_outlets: false }] });
    updateUser.mockResolvedValue({ id: 2 });
    setUserOutlets.mockResolvedValue({ id: 2, outlet_ids: [1, 2] });
  });

  async function openEditFor(name) {
    const user = userEvent.setup();
    const row = (await screen.findByText(name)).closest('tr');
    await user.click(within(row).getByRole('button', { name: /edit/i }));
    return user;
  }

  it('shows the current assignments as checkboxes and saves the changed set', async () => {
    renderUsers(TWO);
    const user = await openEditFor('Kasir Satu');

    const group = await screen.findByTestId('user-outlets');
    expect(within(group).getByLabelText('Warkop WJM 1')).toBeChecked();
    await user.click(within(group).getByLabelText('Warkop WJM 2'));
    await user.click(screen.getByRole('button', { name: /^simpan/i }));

    await waitFor(() => expect(setUserOutlets).toHaveBeenCalledWith(2, [1, 2]));
  });

  it('hides the assignment at a single outlet', async () => {
    renderUsers([TWO[0]]);
    await openEditFor('Kasir Satu');

    await screen.findByDisplayValue('kasir01');
    expect(screen.queryByTestId('user-outlets')).not.toBeInTheDocument();
  });

  it('hides the assignment for a role that sees every outlet', async () => {
    renderUsers(TWO);
    await openEditFor('owner');

    await screen.findByDisplayValue('owner');
    expect(screen.queryByTestId('user-outlets')).not.toBeInTheDocument();
    expect(setUserOutlets).not.toHaveBeenCalled();
  });

  it('lists each user\'s outlets in a column (codes, "all outlets", or "not assigned")', async () => {
    renderUsers(TWO);
    await screen.findByText('Kasir Satu');

    expect(screen.getByTestId('user-outlets-all')).toBeInTheDocument();
    expect(screen.getByTestId('user-outlets-codes')).toHaveTextContent('WJ1');
  });

  it('has no outlet column at one outlet', async () => {
    renderUsers([TWO[0]]);
    await screen.findByText('Kasir Satu');

    expect(screen.queryByTestId('user-outlets-codes')).toBeNull();
    expect(screen.queryByRole('columnheader', { name: /Outlet/i })).toBeNull();
  });
});
