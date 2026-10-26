import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import AppSidebar from '../../resources/js/components/layout/AppSidebar.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';

// 004-sidebar-menu-reorg — AppSidebar.vue was never unit-tested before this
// feature (it calls useRoute()/RouterLink, which need a real router
// instance installed, not just a Pinia store). A small dedicated test
// router with trivial stub components is built here rather than importing
// the app's real router, to avoid pulling in every view + its auth guards
// just to render a nav list.
const ROUTE_NAMES = [
  'dashboard', 'pos', 'session', 'events', 'products', 'artists', 'categories',
  'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales', 'reports',
  'activity-log', 'settings', 'users', 'roles', 'profile', 'settlements',
];

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ROUTE_NAMES.map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  return router;
}

async function renderSidebar(menuKeys) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, name: 'Test User', username: 'test', role: 'Owner', menu_keys: menuKeys };
  const router = makeRouter();
  router.push('/dashboard');
  await router.isReady();
  return render(AppSidebar, { global: { plugins: [pinia, router] } });
}

// Full set — used as the "owner" baseline throughout.
const ALL_MENU_KEYS = [
  'dashboard', 'pos', 'session', 'events', 'products', 'artists', 'categories',
  'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales', 'reports',
  'settings', 'users', 'roles',
];

describe('AppSidebar — order and grouping (004-sidebar-menu-reorg)', () => {
  beforeEach(() => {});

  it('renders top-level items in the requested order: Sesi Kasir, Sales, Pembelian, Inventaris, Pre-orders', async () => {
    await renderSidebar(ALL_MENU_KEYS);
    const nav = screen.getByRole('navigation');

    // Top-level <li> elements render in DOM order — just confirm the
    // relative order of the items this feature actually touches.
    const items = Array.from(nav.querySelectorAll(':scope > ul > li')).map((li) => li.textContent.replace(/\s+/g, ' ').trim());
    const idxSession = items.findIndex((t) => t.includes('Sesi Kasir'));
    const idxSales = items.findIndex((t) => t.includes('Penjualan'));
    const idxPembelian = items.findIndex((t) => t.includes('Pembelian'));
    const idxInventaris = items.findIndex((t) => t.includes('Inventaris'));
    const idxPreorders = items.findIndex((t) => t.includes('Pre-order'));

    expect(idxSession).toBeGreaterThanOrEqual(0);
    expect(idxSales).toBeGreaterThan(idxSession);
    expect(idxPembelian).toBeGreaterThan(idxSales);
    expect(idxInventaris).toBeGreaterThan(idxPembelian);
    expect(idxPreorders).toBeGreaterThan(idxInventaris);
  });

  it('groups Kategori, Produk, Stok under "Inventaris" in that order', async () => {
    await renderSidebar(ALL_MENU_KEYS);
    await screen.findByText('Inventaris');
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /Inventaris/ }));

    const group = screen.getByRole('button', { name: /Inventaris/ }).closest('li');
    const children = Array.from(group.querySelectorAll('ul li')).map((li) => li.textContent.trim());
    expect(children).toEqual(['Kategori', 'Produk', 'Stok']);
  });

  it('groups Vendor and Bahan Baku under "Pembelian"', async () => {
    await renderSidebar(ALL_MENU_KEYS);
    await screen.findByText('Pembelian');
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /Pembelian/ }));

    const group = screen.getByRole('button', { name: /Pembelian/ }).closest('li');
    const children = Array.from(group.querySelectorAll('ul li')).map((li) => li.textContent.trim());
    expect(children).toEqual(['Vendor', 'Bahan Baku']);
  });

  it('hides a child within a group the role cannot access, without hiding the whole group', async () => {
    await renderSidebar(ALL_MENU_KEYS.filter((k) => k !== 'products'));
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /Inventaris/ }));

    expect(screen.getByText('Kategori')).toBeInTheDocument();
    expect(screen.getByText('Stok')).toBeInTheDocument();
    expect(screen.queryByText('Produk')).not.toBeInTheDocument();
  });

  it('hides the "Inventaris" group entirely when none of its children are accessible', async () => {
    const kasirKeys = ['dashboard', 'pos', 'session', 'events', 'customers', 'preorders', 'sales'];
    await renderSidebar(kasirKeys);
    expect(screen.queryByText('Inventaris')).not.toBeInTheDocument();
  });

  it('hides the "Pembelian" group entirely when neither Vendor nor Bahan Baku is accessible', async () => {
    const kasirKeys = ['dashboard', 'pos', 'session', 'events', 'customers', 'preorders', 'sales'];
    await renderSidebar(kasirKeys);
    expect(screen.queryByText('Pembelian')).not.toBeInTheDocument();
  });
});

describe('AppSidebar — brand name follows the configurable app name', () => {
  async function renderWithAppName(appName) {
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, name: 'Test User', username: 'test', role: 'Owner', menu_keys: ['dashboard'] };
    if (appName !== undefined) useSettingsStore().appName = appName;
    const router = makeRouter();
    router.push('/dashboard');
    await router.isReady();
    return render(AppSidebar, { global: { plugins: [pinia, router] } });
  }

  it('shows BoothPOS by default', async () => {
    await renderWithAppName(undefined);

    expect(screen.getByText('BoothPOS')).toBeInTheDocument();
  });

  it('shows the configured name instead of the hard-coded one', async () => {
    await renderWithAppName('Kasir Sakana');

    expect(screen.getByText('Kasir Sakana')).toBeInTheDocument();
    expect(screen.queryByText('BoothPOS')).not.toBeInTheDocument();
  });
});

// 076-settlement-tab-session-branding (US1/FR-005) — "buka di tab baru" (klik
// tengah, Ctrl/Cmd-klik) hanya bekerja bila setiap item menu adalah tautan
// sungguhan, bukan tombol. Penyebab login ulang di tab baru ada di
// penyimpanan token, BUKAN di sini; test ini menjaga agar sisi tautannya
// tidak pernah mundur menjadi <button>/@click.
describe('AppSidebar — items are real links (new-tab support)', () => {
  it('renders every leaf item as an <a> with an href to its own route', async () => {
    await renderSidebar(ALL_MENU_KEYS);
    const nav = screen.getByRole('navigation');

    for (const [label, href] of [['Beranda', '/dashboard'], ['Penjualan', '/sales']]) {
      const link = Array.from(nav.querySelectorAll('a')).find((a) => a.textContent.includes(label));
      expect(link, `tautan "${label}" tidak ditemukan`).toBeTruthy();
      expect(link.tagName).toBe('A');
      expect(link.getAttribute('href')).toBe(href);
    }
  });

  it('gives every navigable item an href (no onclick-only navigation)', async () => {
    await renderSidebar(ALL_MENU_KEYS);
    const nav = screen.getByRole('navigation');
    const links = Array.from(nav.querySelectorAll('a'));

    expect(links.length).toBeGreaterThan(5);
    for (const a of links) {
      expect(a.getAttribute('href')).toMatch(/^\//);
    }
  });
});

// 076 (US2/FR-006) — item "Settlement" mengikuti menu key 'settlements' (hanya kosmetik; server yang menegakkan).
describe('AppSidebar — Settlement item follows the settlements menu key', () => {
  it('is shown to a role that has the key, as a real link', async () => {
    await renderSidebar([...ALL_MENU_KEYS, 'settlements']);
    const link = screen.getByRole('link', { name: /settlement/i });

    expect(link.getAttribute('href')).toBe('/settlements');
  });

  it('is hidden entirely from a role without the key', async () => {
    await renderSidebar(ALL_MENU_KEYS);

    expect(screen.queryByRole('link', { name: /settlement/i })).not.toBeInTheDocument();
  });
});

// 076 (US4) — blok merek sidebar memakai logo & nama TOKO; tanpa keduanya kembali ke ikon bawaan & nama aplikasi.
describe('AppSidebar — brand block follows the store identity', () => {
  async function renderBrand(configure) {
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, name: 'Test User', username: 'test', role: 'Owner', menu_keys: ['dashboard'] };
    configure(useSettingsStore());
    const router = makeRouter();
    router.push('/dashboard');
    await router.isReady();
    return render(AppSidebar, { global: { plugins: [pinia, router] } });
  }

  it('shows the store logo, the store name and the tier label', async () => {
    await renderBrand((s) => {
      s.storeName = 'Demo Store';
      s.storeLogoUrl = '/storage/store-logo/abc.png';
      s.multiArtistEnabled = true;
    });

    expect(screen.getByTestId('brand-logo').getAttribute('src')).toBe('/storage/store-logo/abc.png');
    expect(screen.getByTestId('brand-name')).toHaveTextContent('Demo Store');
    expect(screen.getByText('Master')).toBeInTheDocument();
    expect(screen.queryByTestId('brand-icon')).not.toBeInTheDocument();
  });

  it('falls back to the app name when the store name is blank, and to the default mark without a logo', async () => {
    await renderBrand((s) => {
      s.storeName = '  ';
      s.storeLogoUrl = null;
      s.appName = 'Kasir Sakana';
    });

    expect(screen.getByTestId('brand-name')).toHaveTextContent('Kasir Sakana');
    expect(screen.getByTestId('brand-icon')).toBeInTheDocument();
    expect(screen.queryByTestId('brand-logo')).not.toBeInTheDocument();
  });

  it('swaps a logo that fails to load for the default mark (no broken image)', async () => {
    await renderBrand((s) => {
      s.storeName = 'Demo Store';
      s.storeLogoUrl = '/storage/missing.png';
    });

    await fireEvent.error(screen.getByTestId('brand-logo'));

    expect(screen.queryByTestId('brand-logo')).not.toBeInTheDocument();
    expect(screen.getByTestId('brand-icon')).toBeInTheDocument();
  });

  it('keeps the full store name available as a tooltip when it is long', async () => {
    const long = 'Toko Merchandise Event Panjang Sekali Untuk Uji Pemotongan';
    await renderBrand((s) => {
      s.storeName = long;
    });

    expect(screen.getByTestId('brand-name').getAttribute('title')).toBe(long);
    expect(screen.getByTestId('brand-name').className).toMatch(/truncate/);
  });
});
