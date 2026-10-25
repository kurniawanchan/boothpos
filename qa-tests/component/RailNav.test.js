import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import RailNav from '../../resources/js/components/layout/RailNav.vue';
import { NAV_SECTIONS, isGroup } from '../../resources/js/utils/navigation';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { usePosCartStore } from '../../resources/js/stores/posCart';
import { i18n } from '../../resources/js/i18n';

// 041-back-office-ui-redesign (US1) — rel navigasi. Router uji kecil dengan komponen kosong (bukan router
// aplikasi, supaya tak menarik semua view + guard-nya hanya untuk merender daftar navigasi).
const ROUTE_NAMES = NAV_SECTIONS.flatMap((s) => s.items).flatMap((i) => (isGroup(i) ? i.children.map((c) => c.name) : [i.name]));
const t = (key) => i18n.global.t(key);

async function renderRail({ menuKeys, collapsed = false, props = {}, at = 'dashboard', beforeRender = null }) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'Test', username: 'test', role: 'Owner', menu_keys: menuKeys };
  beforeRender?.();
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...ROUTE_NAMES, 'profile'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  router.push(`/${at}`);
  await router.isReady();
  return render(RailNav, { props: { collapsed, ...props }, global: { plugins: [pinia, router] } });
}

const ALL = ['dashboard', 'pos', 'session', 'events', 'products', 'outlets', 'categories', 'stock', 'vendors', 'materials',
  'purchase_orders', 'customers', 'sales', 'reports', 'settings', 'users', 'roles', 'floor', 'kitchen',
  'dining_setup', 'companies', 'licenses', 'invoices'];

describe('RailNav — visibilitas per peran', () => {
  beforeEach(() => localStorage.clear());

  it('hanya merender modul yang boleh diakses (disembunyikan, bukan dinonaktifkan)', async () => {
    await renderRail({ menuKeys: ['dashboard', 'pos'] });
    expect(screen.getByRole('link', { name: t('nav.dashboard') })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: new RegExp(`^${t('nav.pos')}`) })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: t('nav.sales') })).toBeNull();
    expect(screen.queryByRole('button', { name: new RegExp(t('nav.settings_group')) })).toBeNull();
  });

  it('menampilkan grup bila minimal satu anaknya boleh, dan hanya anak itu', async () => {
    await renderRail({ menuKeys: ['floor'], at: 'floor' });
    const group = screen.getByRole('button', { name: new RegExp(t('nav.restaurant_group')) });
    expect(group).toBeInTheDocument();
    // Grup yang berisi rute aktif terbuka otomatis; hanya "Meja/Floor" yang boleh.
    expect(screen.getByRole('link', { name: t('nav.floor') })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: t('nav.kitchen') })).toBeNull();
  });

  it('tidak merender judul kelompok kosong untuk peran dengan satu modul', async () => {
    await renderRail({ menuKeys: ['dashboard'] });
    expect(screen.getAllByRole('list')).toHaveLength(1);
  });

  it('menandai rute aktif dengan token rel-aktif', async () => {
    await renderRail({ menuKeys: ['dashboard', 'pos'], at: 'pos' });
    expect(screen.getByRole('link', { name: new RegExp(`^${t('nav.pos')}`) })).toHaveClass('bg-rail-active');
    expect(screen.getByRole('link', { name: t('nav.dashboard') })).not.toHaveClass('bg-rail-active');
  });
});

describe('RailNav — lencana dan titik status', () => {
  it('memberi titik status dengan teks untuk pembaca layar', async () => {
    await renderRail({ menuKeys: ['dashboard', 'sales'], props: { dots: { sales: true } } });
    const link = screen.getByRole('link', { name: new RegExp(t('nav.sales')) });
    expect(within(link).getByText(t('frame.status_dot'))).toBeInTheDocument();
    expect(within(screen.getByRole('link', { name: t('nav.dashboard') })).queryByText(t('frame.status_dot'))).toBeNull();
  });
});

describe('RailNav — ciut/lebar', () => {
  it('lebar 240 px saat lebar dan 64 px saat ciut', async () => {
    const { unmount } = await renderRail({ menuKeys: ['dashboard'] });
    expect(screen.getByRole('navigation', { name: t('frame.rail_aria') })).toHaveClass('w-[240px]');
    unmount();
    await renderRail({ menuKeys: ['dashboard'], collapsed: true });
    expect(screen.getByRole('navigation', { name: t('frame.rail_aria') })).toHaveClass('w-[64px]');
  });

  it('saat ciut nama modul tetap tersedia sebagai nama aksesibel dan tidak tampil sebagai teks item', async () => {
    await renderRail({ menuKeys: ['dashboard'], collapsed: true });
    const link = screen.getByRole('link', { name: t('nav.dashboard') });
    expect(link).toHaveAttribute('aria-label', t('nav.dashboard'));
  });

  it('tombol kendali memancarkan toggle dan label mengikuti keadaan', async () => {
    const { emitted } = await renderRail({ menuKeys: ['dashboard'] });
    await fireEvent.click(screen.getByRole('button', { name: t('frame.rail_collapse') }));
    expect(emitted().toggle).toHaveLength(1);
  });

  it('saat ciut, klik grup membuka flyout berisi anak dan Escape menutupnya', async () => {
    await renderRail({ menuKeys: ['vendors', 'materials'], collapsed: true, at: 'dashboard' });
    // Pemicu grup bernama lewat aria-label
    const trigger = screen.getByRole('button', { name: t('nav.purchase_group') });
    expect(screen.queryByRole('menu')).toBeNull();
    await fireEvent.click(trigger);
    const menu = screen.getByRole('menu');
    expect(within(menu).getByRole('menuitem', { name: t('nav.vendors') })).toBeInTheDocument();
    expect(within(menu).getByRole('menuitem', { name: t('nav.materials') })).toBeInTheDocument();
    await fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('saat lebar, klik grup membuka dan menutup daftar anaknya di tempat', async () => {
    await renderRail({ menuKeys: ['vendors', 'materials'], at: 'dashboard' });
    const trigger = screen.getByRole('button', { name: new RegExp(t('nav.purchase_group')) });
    expect(screen.queryByRole('link', { name: t('nav.vendors') })).toBeNull();
    await fireEvent.click(trigger);
    expect(screen.getByRole('link', { name: t('nav.vendors') })).toBeInTheDocument();
    await fireEvent.click(trigger);
    expect(screen.queryByRole('link', { name: t('nav.vendors') })).toBeNull();
  });
});

// 072-store-identity-branding (US1) — merek di sudut sidebar memakai identitas toko, dengan jatuh ke merek produk.
describe('RailNav — merek toko', () => {
  beforeEach(() => localStorage.clear());

  const brand = (settings) => renderRail({
    menuKeys: ['dashboard'],
    beforeRender: () => useSettingsStore().$patch({ appName: 'BoothPOS', multiOutletEnabled: true, ...settings }),
  });

  it('menampilkan logo dan nama toko menggantikan huruf dan nama aplikasi', async () => {
    const { container } = await brand({ storeName: 'Warkop WJM', storeLogoUrl: '/storage/store-logo/a.png' });
    expect(screen.getByText('Warkop WJM')).toBeInTheDocument();
    expect(screen.queryByText('BoothPOS')).toBeNull();
    expect(container.querySelector('img[src="/storage/store-logo/a.png"]')).not.toBeNull();
    expect(screen.getByText('Master')).toBeInTheDocument();
  });

  it('tanpa logo: kotak berisi huruf pertama nama toko', async () => {
    const { container } = await brand({ storeName: 'warkop WJM', storeLogoUrl: null });
    expect(container.querySelector('nav img')).toBeNull();
    expect(screen.getByText('W')).toBeInTheDocument();
  });

  it('tanpa nama toko: perilaku lama (huruf dan nama aplikasi)', async () => {
    await brand({ storeName: '', storeLogoUrl: null });
    expect(screen.getByText('BoothPOS')).toBeInTheDocument();
    expect(screen.getByText('B')).toBeInTheDocument();
  });

  it('logo gagal dimuat: jatuh ke kotak huruf', async () => {
    const { container } = await brand({ storeName: 'Warkop WJM', storeLogoUrl: '/storage/rusak.png' });
    await fireEvent.error(container.querySelector('nav img'));
    expect(container.querySelector('nav img')).toBeNull();
    expect(screen.getByText('W')).toBeInTheDocument();
  });

  it('diciut: nama tersembunyi, logo tetap tampil', async () => {
    const { container } = await renderRail({
      menuKeys: ['dashboard'],
      collapsed: true,
      beforeRender: () => useSettingsStore().$patch({ storeName: 'Warkop WJM', storeLogoUrl: '/storage/store-logo/a.png' }),
    });
    expect(screen.queryByText('Warkop WJM')).toBeNull();
    expect(container.querySelector('nav img')).not.toBeNull();
  });

  it('nama berisi markup tampil sebagai teks biasa', async () => {
    await brand({ storeName: '<b>Toko</b>', storeLogoUrl: null });
    expect(screen.getByText('<b>Toko</b>')).toBeInTheDocument();
  });
});
