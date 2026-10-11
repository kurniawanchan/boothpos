import { render } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';

// 043 — bantuan bersama tes halaman Produk (Grid/Tabel/Struktur). vi.mock tetap di tiap berkas tes (di-hoist).
export const ROW = (id, extra = {}) => ({
  id, code: `KOP-00${id}`, name: `Item ${id}`, short_name: null, image_url: null,
  category: { id: 3, name: 'Kopi' }, station: { id: 1, name: 'Bar', source: 'category' },
  status: 'active', is_retail: false, base_price: '8000.00', price_range: { min: '8000.00', max: '8000.00' },
  variants_summary: 'Panas / Es', variant_count: 2, modifier_summary: ['Gula'],
  availability: [{ outlet_id: null, outlet_code: null, state: 'available', reason: null }], food_cost_pct: null, quality: [], ...extra,
});
export const COUNTS = { menu: 24, material: 18, semi_finished: 6, packaging: 7, retail: 4, modifier: 7 };
export const SUMMARY = {
  release_enabled: false,
  active: { count: 22, total: 24, draft: 1, discontinued: 1 },
  sold_out_today: { count: 6, examples: [{ product: 'Es Jeruk', outlet: null }] },
  unreleased: null,
  quality: { count: 2, examples: ['Tahu Crispy'], kind: 'no_station' },
};
export const META = (total = 24, page = 1) => ({ current_page: page, per_page: 15, total, last_page: Math.max(1, Math.ceil(total / 15)) });

export async function renderProducts({ menus = ['dashboard', 'products'], url = '/products', userId = 1 } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: userId, role: 'Owner', name: 'Owner', menu_keys: menus };
  useSettingsStore().menuReleaseEnabled = false;
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/products', name: 'products', component: { template: '<div />' } },
      { path: '/products/:id', name: 'product-detail', component: { template: '<div />' } },
    ],
  });
  router.push(url);
  await router.isReady();
  const view = render(ProductsView, { global: { plugins: [pinia, router] } });
  return { ...view, router };
}

export async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}
