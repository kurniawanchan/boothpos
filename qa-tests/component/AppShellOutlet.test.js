import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import AppShell from '../../resources/js/components/layout/AppShell.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { selectedOutletId } from '../../resources/js/utils/outletSelection';

// 045 Stage 2 (T042) — pemilih outlet di topbar mengikuti GET /me/scope: tampil hanya bila lebih dari satu
// outlet, menyimpan pilihan per pengguna, dan memuat ulang halaman agar setiap layar mengambil data dengan header baru.
const getScope = vi.fn();
vi.mock('../../resources/js/api/me', () => ({ getScope: (...a) => getScope(...a) }));
vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn().mockResolvedValue({ multi_outlet_enabled: true, outlet_count: 2, outlet_limit_reached: false, system_mode: 'live', store_name: 'Toko' }),
  updateSettings: vi.fn(),
}));
vi.mock('../../resources/js/api/preorders', () => ({ listPreorders: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) }));

const TWO = {
  outlets: [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }],
  selected: null, can_see_all: true, multi: true,
};

async function renderShell() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 7, name: 'Owner', username: 'owner', role: 'Owner', menu_keys: ['dashboard'] };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/dashboard', name: 'dashboard', component: { template: '<div>Beranda</div>' } },
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
    ],
  });
  router.push('/dashboard');
  await router.isReady();
  return render(AppShell, { global: { plugins: [pinia, router] } });
}

describe('AppShell outlet selector (045 Stage 2)', () => {
  const reload = vi.fn();

  beforeEach(() => {
    localStorage.clear();
    getScope.mockReset();
    reload.mockReset();
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, reload } });
  });

  it('shows the selector when the user can see more than one outlet', async () => {
    getScope.mockResolvedValue(TWO);
    await renderShell();

    expect(await screen.findByRole('combobox')).toBeInTheDocument();
  });

  it('hides the selector at a single outlet', async () => {
    getScope.mockResolvedValue({ outlets: [TWO.outlets[0]], selected: null, can_see_all: false, multi: false });
    await renderShell();

    await waitFor(() => expect(getScope).toHaveBeenCalled());
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('stores the choice per user and reloads so every screen refetches', async () => {
    getScope.mockResolvedValue(TWO);
    const user = userEvent.setup();
    await renderShell();

    await user.selectOptions(await screen.findByRole('combobox'), '2');

    expect(selectedOutletId()).toBe(2);
    expect(localStorage.getItem('boothpos.outlet.7')).toBe('2');
    expect(reload).toHaveBeenCalledTimes(1);
  });
});
