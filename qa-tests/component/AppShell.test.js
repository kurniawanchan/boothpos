import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import { defineComponent, h } from 'vue';
import AppShell from '../../resources/js/components/layout/AppShell.vue';
import { registerPageHeader, customHeaderCount } from '../../resources/js/composables/usePageHeader';
import { useAuthStore } from '../../resources/js/stores/auth';
import { i18n } from '../../resources/js/i18n';

// 041-back-office-ui-redesign (US1) — kerangka baru: rel navigasi + topbar + konten. Menggantikan tes
// sidebar/topbar lama (lebar 228 px, kunci `boothpos:sidebar-visible`, tombol "tampilkan sidebar").
vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn().mockResolvedValue({
    multi_outlet_enabled: false, outlet_count: 0, outlet_limit_reached: false, system_mode: 'live', store_name: 'Sakana Fridge',
  }),
  updateSettings: vi.fn(),
}));
vi.mock('../../resources/js/api/preorders', () => ({
  listPreorders: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }),
}));
vi.mock('../../resources/js/api/events', () => ({
  listEvents: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'Comic Frontier 2026' }] }),
}));

const t = (key) => i18n.global.t(key);

const PageWithHeader = defineComponent({
  setup() {
    registerPageHeader();
    return () => h('div', [h('h1', { id: 'page-heading' }, 'Judul dari PageHeader')]);
  },
});

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: { template: '<div>Masuk</div>' } },
      { path: '/dashboard', name: 'dashboard', component: { template: '<div>Isi beranda</div>' } },
      { path: '/sales', name: 'sales', component: { template: '<div>Isi sales</div>' } },
      { path: '/custom', name: 'custom', component: PageWithHeader, meta: { titleKey: 'nav.sales' } },
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
    ],
  });
}

async function renderShell({ at = '/dashboard', user = {} } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, name: 'Test User', username: 'test', role: 'Owner', menu_keys: ['dashboard', 'sales'], ...user };
  const router = makeRouter();
  router.push(at);
  await router.isReady();
  const view = render(AppShell, { global: { plugins: [pinia, router] } });
  return { ...view, router, auth };
}

describe('AppShell — komposisi kerangka', () => {
  beforeEach(() => {
    localStorage.clear();
    customHeaderCount.value = 0;
  });

  it('merender rel navigasi, topbar dengan jejak halaman, dan konten halaman', async () => {
    await renderShell();
    expect(screen.getByRole('navigation', { name: t('frame.rail_aria') })).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: t('frame.breadcrumb_aria') })).toBeInTheDocument();
    expect(screen.getByRole('main')).toHaveTextContent('Isi beranda');
  });

  it('menyediakan tautan "lewati ke konten" yang menuju area utama', async () => {
    await renderShell();
    const skip = screen.getByRole('link', { name: t('frame.skip_to_content') });
    expect(skip).toHaveAttribute('href', '#main-content');
    expect(screen.getByRole('main')).toHaveAttribute('id', 'main-content');
  });

  it('memuat nama toko dan event aktif ke topbar', async () => {
    await renderShell();
    await waitFor(() => expect(screen.getByTestId('store-label')).toHaveTextContent('Sakana Fridge · Comic Frontier 2026'));
  });

  it('menampilkan judul bawaan dari route meta untuk halaman yang belum memakai PageHeader', async () => {
    await renderShell({ at: '/sales' });
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(t('nav.sales'));
  });

  it('menyembunyikan judul bawaan begitu halaman memakai PageHeader (tidak pernah dua judul)', async () => {
    await renderShell({ at: '/custom' });
    await waitFor(() => expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1));
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Judul dari PageHeader');
  });

  it('keluar memanggil auth.logout lalu pindah ke halaman login', async () => {
    const user = userEvent.setup();
    const { auth, router } = await renderShell();
    auth.logout = vi.fn().mockResolvedValue();
    await user.click(screen.getByRole('button', { name: t('frame.user_menu') }));
    await user.click(screen.getByRole('menuitem', { name: t('nav.logout') }));
    expect(auth.logout).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
  });
});

describe('AppShell — rel ciut/lebar', () => {
  beforeEach(() => {
    localStorage.clear();
    customHeaderCount.value = 0;
  });

  it('lebar secara bawaan', async () => {
    await renderShell();
    expect(screen.getByRole('navigation', { name: t('frame.rail_aria') })).toHaveClass('w-[240px]');
  });

  it('mengciutkan rel saat tombol diklik dan mengingat pilihan per pengguna', async () => {
    const user = userEvent.setup();
    await renderShell();
    await user.click(screen.getByRole('button', { name: t('frame.rail_collapse') }));
    expect(screen.getByRole('navigation', { name: t('frame.rail_aria') })).toHaveClass('w-[64px]');
    expect(localStorage.getItem('boothpos:rail-collapsed:1')).toBe('collapsed');
    // tombol kini mengembalikan lebar
    expect(screen.getByRole('button', { name: t('frame.rail_expand') })).toBeInTheDocument();
  });

  it('memulihkan pilihan ciut saat dimuat ulang', async () => {
    localStorage.setItem('boothpos:rail-collapsed:1', 'collapsed');
    await renderShell();
    expect(screen.getByRole('navigation', { name: t('frame.rail_aria') })).toHaveClass('w-[64px]');
  });

  it('pilihan pengguna lain di perangkat yang sama tidak ikut terbawa', async () => {
    localStorage.setItem('boothpos:rail-collapsed:2', 'collapsed');
    await renderShell();
    expect(screen.getByRole('navigation', { name: t('frame.rail_aria') })).toHaveClass('w-[240px]');
  });
});
