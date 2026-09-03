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

// Sidebar tetap ter-mount SELAMA transisi (lihat komentar AppShell.vue) —
// jadi status tampil/sembunyi diverifikasi lewat lebar wrapper +
// aria-hidden/inert, bukan ada/tidaknya elemen <nav> di DOM. `hidden: true`
// wajib di sini karena begitu aria-hidden="true" terpasang, query role
// default testing-library MEMANG mengecualikannya dari accessibility tree
// (perilaku yang benar) — kita tetap perlu menjangkau elemennya untuk
// memeriksa lebar/atributnya sendiri.
function sidebarWrapper() {
  return screen.getByRole('navigation', { name: /navigasi utama/i, hidden: true }).parentElement;
}

describe('AppShell — sidebar show/hide toggle', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('shows the sidebar by default (expanded width, not aria-hidden)', async () => {
    await renderShell();
    const wrapper = sidebarWrapper();
    expect(wrapper.style.width).toBe('228px');
    expect(wrapper.getAttribute('aria-hidden')).toBe('false');
    expect(screen.queryByRole('button', { name: /tampilkan sidebar/i })).not.toBeInTheDocument();
  });

  it('collapses the sidebar and shows a reveal button in the topbar when the hide toggle is clicked, persisting the choice', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderShell();

    await user.click(screen.getByRole('button', { name: /sembunyikan sidebar/i }));

    const wrapper = sidebarWrapper();
    expect(wrapper.style.width).toBe('0px');
    expect(wrapper.getAttribute('aria-hidden')).toBe('true');
    expect(screen.getByRole('button', { name: /tampilkan sidebar/i })).toBeInTheDocument();
    expect(localStorage.getItem('boothpos:sidebar-visible')).toBe('hidden');
  });

  it('expands the sidebar again when the reveal button is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderShell();

    await user.click(screen.getByRole('button', { name: /sembunyikan sidebar/i }));
    await user.click(screen.getByRole('button', { name: /tampilkan sidebar/i }));

    const wrapper = sidebarWrapper();
    expect(wrapper.style.width).toBe('228px');
    expect(localStorage.getItem('boothpos:sidebar-visible')).toBe('visible');
  });

  it('restores a previously hidden sidebar preference on mount', async () => {
    localStorage.setItem('boothpos:sidebar-visible', 'hidden');
    await renderShell();

    expect(sidebarWrapper().style.width).toBe('0px');
    expect(screen.getByRole('button', { name: /tampilkan sidebar/i })).toBeInTheDocument();
  });
});
