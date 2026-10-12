import { render } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { vi } from 'vitest';
import ProductFormDrawer from '../../resources/js/views/products/ProductFormDrawer.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useOutletStore } from '../../resources/js/stores/outlet';

// 043 — inang kecil untuk laci tambah/ubah item. Di aplikasi laci UBAH dibuka dari tombol "Edit item" halaman item
// (ProductDetailView) dan laci BUAT dari tombol "Tambah item" halaman daftar; tes perilaku laci tidak perlu
// memasang kedua halaman itu, jadi inang ini meniru kedua pemicunya: "Ubah <nama>" -> openEdit({ id }) dan
// "Tambah item" -> openCreate(). `saved` merekam payload event `saved` ({ id, created }).
// vi.mock tetap di tiap berkas tes (di-hoist), bukan di sini.
export function renderDrawerHost({
  menus = ['dashboard', 'products'],
  release = false,
  product = { id: 1, name: 'Keychain A' },
  outlets = [{ id: 1, name: 'Outlet A', code: 'ART' }],
  categories = [{ id: 1, name: 'Kategori A', code: 'KA' }],
  // 045 Stage 3 — cakupan outlet pengguna ({ outlets: [{id, name}], multi }); tanpa nilai = satu outlet (kolom tersembunyi).
  scope = null,
} = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menus };
  useSettingsStore().menuReleaseEnabled = release;
  if (scope) Object.assign(useOutletStore(), { outlets: scope.outlets, multi: scope.multi ?? scope.outlets.length > 1 });
  const saved = vi.fn();
  const Host = {
    components: { ProductFormDrawer },
    props: ['outlets', 'categories', 'productId', 'productName', 'onSaved'],
    template: `<div>
      <span>{{ productName }}</span>
      <button type="button" @click="$refs.drawer.openEdit({ id: productId })">Ubah {{ productName }}</button>
      <button type="button" @click="$refs.drawer.openCreate()">Tambah item</button>
      <ProductFormDrawer ref="drawer" :outlets="outlets" :categories="categories" @saved="onSaved" />
    </div>`,
  };
  const view = render(Host, {
    props: { outlets, categories, productId: product.id, productName: product.name, onSaved: saved },
    global: { plugins: [pinia] },
  });
  return { ...view, saved };
}
