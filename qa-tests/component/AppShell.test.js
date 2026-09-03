import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import AppShell from '../../resources/js/components/layout/AppShell.vue';
import { useAuthStore } from '../../resources/js/stores/auth';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn().mockResolvedValue({ multi_artist_enabled: false, artist_count: 0, artist_limit_reached: false, system_mode: 'live' }),
  updateSettings: vi.fn(),
}));
vi.mock('../../resources/js/api/preorders', () => ({
  listPreorders: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }),
}));

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', name: 'dashboard', component: { template: '<div>Beranda</div>' } },
      { path: '/profile', name: 'profile', component: { template: '<div>Profil</div>' } },
    ],
  });
  return router;
}

async function renderShell() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, name: 'Test User', username: 'test', role: 'Owner', menu_keys: ['dashboard'] };
  const router = makeRouter();
  router.push('/dashboard');
  await router.isReady();
  return render(AppShell, { global: { plugins: [pinia, router] } });
}

// Sidebar show/hide toggle
describe('AppShell — sidebar show/hide toggle', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('shows the sidebar by default', async () => {
    await renderShell();
    expect(screen.getByRole('navigation', { name: /navigasi utama/i })).toBeInTheDocument();
  });

  it('hides the sidebar and shows a reveal button when the hide toggle is clicked, persisting the choice', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderShell();

    await user.click(screen.getByRole('button', { name: /sembunyikan sidebar/i }));

    expect(screen.queryByRole('navigation', { name: /navigasi utama/i })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /tampilkan sidebar/i })).toBeInTheDocument();
    expect(localStorage.getItem('boothpos:sidebar-visible')).toBe('hidden');
  });

  it('shows the sidebar again when the reveal button is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderShell();

    await user.click(screen.getByRole('button', { name: /sembunyikan sidebar/i }));
    await user.click(screen.getByRole('button', { name: /tampilkan sidebar/i }));

    expect(screen.getByRole('navigation', { name: /navigasi utama/i })).toBeInTheDocument();
    expect(localStorage.getItem('boothpos:sidebar-visible')).toBe('visible');
  });

  it('restores a previously hidden sidebar preference on mount', async () => {
    localStorage.setItem('boothpos:sidebar-visible', 'hidden');
    await renderShell();

    expect(screen.queryByRole('navigation', { name: /navigasi utama/i })).not.toBeInTheDocument();
  });
});
