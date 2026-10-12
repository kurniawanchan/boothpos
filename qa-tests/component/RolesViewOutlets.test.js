import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import RolesView from '../../resources/js/views/RolesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { listRoles, updateRole } from '../../resources/js/api/roles';

// 045 Stage 2 — peran "Melihat semua outlet": hanya bila ada beberapa outlet dan pengguna sendiri melihat semuanya.
vi.mock('../../resources/js/api/roles', () => ({
  listRoles: vi.fn(), getRole: vi.fn(), createRole: vi.fn(), updateRole: vi.fn(), deleteRole: vi.fn(),
  listPermissionCatalog: vi.fn().mockResolvedValue({ data: [] }),
  listMenuKeys: vi.fn().mockResolvedValue({ data: [{ key: 'pos', label: 'Kasir' }] }),
}));

function renderRoles({ multi = true, canSeeAll = true } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'roles'] };
  Object.assign(useOutletStore(), { multi, canSeeAll, outlets: [{ id: 1, code: 'WJ1', name: 'W1' }, { id: 2, code: 'WJ2', name: 'W2' }] });
  return render(RolesView, { global: { plugins: [pinia] } });
}

async function openEdit(name) {
  const user = userEvent.setup();
  const row = (await screen.findByText(name)).closest('tr');
  await user.click(within(row).getByRole('button', { name: /edit/i }));
  return user;
}

describe('RolesView all-outlets flag (045)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listRoles.mockResolvedValue({
      data: [
        { id: 1, name: 'Kasir Event A', menu_keys: ['pos'], is_system_default: false, all_outlets: false },
        { id: 2, name: 'Owner', menu_keys: ['pos'], is_system_default: true, all_outlets: true },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    updateRole.mockResolvedValue({});
  });

  it('sends all_outlets when the box is ticked', async () => {
    renderRoles();
    const user = await openEdit('Kasir Event A');
    await user.click(await screen.findByLabelText(/Melihat semua outlet/));
    await user.click(screen.getByRole('button', { name: /simpan/i }));

    await waitFor(() => expect(updateRole).toHaveBeenCalled());
    expect(updateRole.mock.calls[0][1].all_outlets).toBe(true);
  });

  it('hides the control at one outlet, for users without full scope, and for the built-in Owner', async () => {
    renderRoles({ multi: false });
    await openEdit('Kasir Event A');
    expect(screen.queryByTestId('role-all-outlets')).toBeNull();
  });

  it('hides it for a user who does not see all outlets', async () => {
    renderRoles({ canSeeAll: false });
    await openEdit('Kasir Event A');
    expect(screen.queryByTestId('role-all-outlets')).toBeNull();
  });

  it('hides it on the built-in Owner role (always on)', async () => {
    renderRoles();
    await openEdit('Owner');
    expect(screen.queryByTestId('role-all-outlets')).toBeNull();
  });
});
