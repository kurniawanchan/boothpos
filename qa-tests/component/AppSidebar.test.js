import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import AppSidebar from '../../resources/js/components/layout/AppSidebar.vue';
import { useAuthStore } from '../../resources/js/stores/auth';

// 004-sidebar-menu-reorg — AppSidebar.vue was never unit-tested before this
// feature (it calls useRoute()/RouterLink, which need a real router
// instance installed, not just a Pinia store). A small dedicated test
// router with trivial stub components is built here rather than importing
// the app's real router, to avoid pulling in every view + its auth guards
// just to render a nav list.
const ROUTE_NAMES = [
  'dashboard', 'pos', 'session', 'events', 'products', 'artists', 'categories',
  'stock', 'vendors', 'materials', 'customers', 'preorders', 'sales', 'reports',
  'activity-log', 'settings', 'users', 'roles', 'profile',
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
