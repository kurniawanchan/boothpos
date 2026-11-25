import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import RolesView from '../../resources/js/views/RolesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { listRoles, createRole, updateRole } from '../../resources/js/api/roles';

// 094 / FR-005 — sakelar "Boleh memakai aplikasi manajemen" di formulir Peran: nilai awal dari server, terkirim di payload
// buat dan ubah. Selalu tampil (bukan hanya multi-outlet): akses aplikasi tidak terkait jumlah outlet.
vi.mock('../../resources/js/api/roles', () => ({
  listRoles: vi.fn(), getRole: vi.fn(), createRole: vi.fn(), updateRole: vi.fn(), deleteRole: vi.fn(),
  listPermissionCatalog: vi.fn().mockResolvedValue({ data: [] }),
  listMenuKeys: vi.fn().mockResolvedValue({ data: [{ key: 'pos', label: 'Kasir' }] }),
}));

function renderRoles() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'roles'] };
  Object.assign(useOutletStore(), { multi: false, canSeeAll: true, outlets: [{ id: 1, code: 'WJ1', name: 'W1' }] });
  return render(RolesView, { global: { plugins: [pinia] } });
}

async function openEdit(name) {
  const user = userEvent.setup();
  const row = (await screen.findByText(name)).closest('tr');
  await user.click(within(row).getByRole('button', { name: /edit/i }));
  return user;
}

describe('RolesView manager app flag (094)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listRoles.mockResolvedValue({
      data: [
        { id: 1, name: 'Kasir Event A', menu_keys: ['pos'], is_system_default: false, all_outlets: false, manager_app_enabled: false },
        { id: 2, name: 'Outlet Manager', menu_keys: ['pos'], is_system_default: false, all_outlets: false, manager_app_enabled: true },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    updateRole.mockResolvedValue({});
    createRole.mockResolvedValue({});
  });

  it('shows the switch with the value from the server', async () => {
    renderRoles();
    await openEdit('Outlet Manager');
    expect(await screen.findByLabelText(/Boleh memakai aplikasi manajemen/)).toBeChecked();
  });

  it('sends manager_app_enabled when the switch is turned on', async () => {
    renderRoles();
    const user = await openEdit('Kasir Event A');
    const box = await screen.findByLabelText(/Boleh memakai aplikasi manajemen/);
    expect(box).not.toBeChecked();
    await user.click(box);
    await user.click(screen.getByRole('button', { name: /simpan/i }));

    await waitFor(() => expect(updateRole).toHaveBeenCalled());
    expect(updateRole.mock.calls[0][1].manager_app_enabled).toBe(true);
  });

  it('a new role starts with the switch off and sends false', async () => {
    renderRoles();
    const user = userEvent.setup();
    await screen.findByText('Kasir Event A');
    await user.click(screen.getByRole('button', { name: /peran baru|tambah peran/i }));
    expect(await screen.findByLabelText(/Boleh memakai aplikasi manajemen/)).not.toBeChecked();
  });
});
