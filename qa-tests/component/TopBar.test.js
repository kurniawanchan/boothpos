import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import TopBar from '../../resources/js/components/layout/TopBar.vue';
import Breadcrumb from '../../resources/js/components/layout/Breadcrumb.vue';
import UserMenu from '../../resources/js/components/layout/UserMenu.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { i18n } from '../../resources/js/i18n';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn().mockResolvedValue({ system_mode: 'live' }),
  updateSettings: vi.fn(),
}));

const t = (key, args) => i18n.global.t(key, args);

async function setup(component, { props = {}, at = '/sales', meta = {} } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'Hendra Wijaya', username: 'hendra', role: 'Owner', menu_keys: ['dashboard', 'sales'] };
  useSettingsStore().loaded = true; // lencana mode hanya tampil setelah pengaturan dimuat
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', name: 'dashboard', component: { template: '<div />' } },
      { path: '/sales', name: 'sales', component: { template: '<div />' }, meta },
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
    ],
  });
  router.push(at);
  await router.isReady();
  return { ...render(component, { props, global: { plugins: [pinia, router] } }), router };
}

describe('Breadcrumb', () => {
  it('menampilkan "Beranda / <halaman>" dengan halaman saat ini bertanda aria-current', async () => {
    await setup(Breadcrumb);
    const nav = screen.getByRole('navigation', { name: t('frame.breadcrumb_aria') });
    expect(within(nav).getByRole('link', { name: t('frame.home') })).toHaveAttribute('href', '/dashboard');
    const current = within(nav).getByText(t('nav.sales'));
    expect(current).toHaveAttribute('aria-current', 'page');
  });

  it('memakai route.meta.titleKey bila ada', async () => {
    await setup(Breadcrumb, { meta: { titleKey: 'nav.stock_page_title' } });
    expect(screen.getByText(t('nav.stock_page_title'))).toBeInTheDocument();
  });

  it('merender trail khusus dan menautkan butir selain yang terakhir', async () => {
    await setup(Breadcrumb, { props: { trail: [{ label: 'Meja', to: '/sales' }, { label: 'Tab #12' }] } });
    expect(screen.getByRole('link', { name: 'Meja' })).toBeInTheDocument();
    expect(screen.getByText('Tab #12')).toHaveAttribute('aria-current', 'page');
  });
});

describe('UserMenu', () => {
  it('tertutup di awal; klik membuka profil, bahasa, dan keluar', async () => {
    await setup(UserMenu);
    expect(screen.queryByRole('menu')).toBeNull();
    await fireEvent.click(screen.getByRole('button', { name: t('frame.user_menu') }));
    const menu = screen.getByRole('menu');
    expect(within(menu).getByRole('menuitem', { name: t('frame.profile') })).toHaveAttribute('href', '/profile');
    expect(within(menu).getByRole('button', { name: 'ID' })).toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: t('nav.logout') })).toBeInTheDocument();
  });

  it('keluar memancarkan logout dan menutup menu', async () => {
    const { emitted } = await setup(UserMenu);
    await fireEvent.click(screen.getByRole('button', { name: t('frame.user_menu') }));
    await fireEvent.click(screen.getByRole('menuitem', { name: t('nav.logout') }));
    expect(emitted().logout).toHaveLength(1);
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('Escape menutup menu dan mengembalikan fokus ke pemicu', async () => {
    await setup(UserMenu);
    const trigger = screen.getByRole('button', { name: t('frame.user_menu') });
    await fireEvent.click(trigger);
    await fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('menu')).toBeNull();
    expect(trigger).toHaveFocus();
  });

  it('klik di luar menutup menu', async () => {
    await setup(UserMenu);
    await fireEvent.click(screen.getByRole('button', { name: t('frame.user_menu') }));
    await fireEvent.pointerDown(document.body);
    expect(screen.queryByRole('menu')).toBeNull();
  });
});

describe('TopBar', () => {
  it('menampilkan nama toko dan event aktif saat tidak ada pemilih outlet', async () => {
    await setup(TopBar, { props: { storeName: 'Sakana Fridge', activeEvent: { id: 1, name: 'Comic Frontier 2026' } } });
    const label = screen.getByTestId('store-label');
    expect(label).toHaveTextContent('Sakana Fridge · Comic Frontier 2026');
  });

  it('tidak merender kendali yang datanya belum ada: pencarian, lonceng, pemilih outlet', async () => {
    await setup(TopBar);
    expect(screen.queryByRole('button', { name: t('frame.search_label') })).toBeNull();
    expect(screen.queryByRole('button', { name: new RegExp(t('frame.notifications')) })).toBeNull();
    expect(screen.queryByRole('combobox')).toBeNull();
  });

  it('merender pencarian, lonceng, dan pemilih outlet ketika datanya diberikan; label toko disembunyikan', async () => {
    await setup(TopBar, {
      props: {
        storeName: 'Toko',
        search: vi.fn(),
        attention: [],
        outlets: [{ id: 1, name: 'WJM 1' }, { id: 2, name: 'WJM 2' }],
      },
    });
    expect(screen.getByRole('button', { name: t('frame.search_label') })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: t('frame.notifications') })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: t('frame.outlet_label') })).toBeInTheDocument();
    expect(screen.queryByTestId('store-label')).toBeNull();
  });

  it('selalu menampilkan status DEMO/LIVE', async () => {
    await setup(TopBar);
    expect(screen.getByTitle(t('settings.system_mode_label'))).toBeInTheDocument();
  });

  it('meneruskan logout dari menu pengguna', async () => {
    const { emitted } = await setup(TopBar);
    await fireEvent.click(screen.getByRole('button', { name: t('frame.user_menu') }));
    await fireEvent.click(screen.getByRole('menuitem', { name: t('nav.logout') }));
    expect(emitted().logout).toHaveLength(1);
  });
});
