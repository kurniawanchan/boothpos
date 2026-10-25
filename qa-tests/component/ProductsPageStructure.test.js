import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { listProducts, productSummary } from '../../resources/js/api/products';
import { listOutlets } from '../../resources/js/api/outlets';
import { listCategories } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), productSummary: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), deleteProduct: vi.fn(),
  addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } }), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const ROW = (id, extra = {}) => ({
  id, code: `KOP-00${id}`, name: `Item ${id}`, short_name: null, image_url: null,
  category: { id: 3, name: 'Kopi' }, station: { id: 1, name: 'Bar', source: 'category' },
  status: 'active', is_retail: false, base_price: '8000.00', price_range: { min: '8000.00', max: '8000.00' },
  variants_summary: 'Panas / Es', variant_count: 2, modifier_summary: ['Gula'],
  availability: [{ outlet_id: null, outlet_code: null, state: 'available', reason: null }], food_cost_pct: null, quality: [], ...extra,
});
const COUNTS = { menu: 24, material: 18, semi_finished: 6, packaging: 7, retail: 4, modifier: 7 };
const SUMMARY = {
  release_enabled: false,
  active: { count: 22, total: 24, draft: 1, discontinued: 1 },
  sold_out_today: { count: 6, examples: [{ product: 'Es Jeruk', outlet: null }] },
  unreleased: null,
  quality: { count: 2, examples: ['Tahu Crispy'], kind: 'no_station' },
};
const META = (total = 24) => ({ current_page: 1, per_page: 15, total, last_page: Math.ceil(total / 15) });

async function renderProducts({ menus = ['dashboard', 'products'], url = '/products', releaseEnabled = false } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menus };
  useSettingsStore().menuReleaseEnabled = releaseEnabled;
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

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T034) — struktur da halaman Produk baru: kepala, angka, tab, filter, daftar, pager.
describe('ProductsView — page structure (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    listProducts.mockResolvedValue({ data: [ROW(1), ROW(2)], meta: META(), counts: COUNTS });
    productSummary.mockResolvedValue(SUMMARY);
    listOutlets.mockResolvedValue({ data: [{ id: 1, name: 'Outlet A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 3, name: 'Kopi', code: 'KP' }] });
  });

  it('shows the PageHeader title and description, with the actions inside the header', async () => {
    await renderProducts();
    await screen.findByText('Item 1');

    const heading = screen.getByRole('heading', { level: 1 });
    expect(heading).toHaveTextContent('Produk');
    expect(heading).toHaveAttribute('id', 'page-heading');
    expect(screen.getByText('Master item · harga belum termasuk pajak')).toBeInTheDocument();
    const header = heading.closest('header');
    expect(within(header).getByRole('button', { name: /tambah item/i })).toBeInTheDocument();
    expect(within(header).getByRole('button', { name: /impor \/ ekspor/i })).toBeInTheDocument();
    // "Ubah harga massal" belum dirender (tugas berikutnya): tidak ada tombol mati.
    expect(screen.queryByRole('button', { name: /ubah harga massal/i })).not.toBeInTheDocument();
  });

  it('adds the active release version to the description only when releases are on', async () => {
    productSummary.mockResolvedValue({ ...SUMMARY, release_enabled: true, unreleased: { count: 3, summary: [], active_version: 42, active_since: '2026-10-01' } });
    await renderProducts({ releaseEnabled: true });
    expect(await screen.findByText('Master item · harga belum termasuk pajak · rilis menu v42 aktif')).toBeInTheDocument();
  });

  it('hides every management action (not disabled) for a role without the products menu', async () => {
    await renderProducts({ menus: ['dashboard'] });
    await screen.findByText('Item 1');

    expect(screen.queryByRole('button', { name: /tambah item/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /impor \/ ekspor/i })).not.toBeInTheDocument();
    expect(screen.queryByText('Edit')).not.toBeInTheDocument();
  });

  it('renders the figure strip from GET /products/summary', async () => {
    await renderProducts();
    await screen.findByText('Item 1');
    expect(within(await screen.findByTestId('figure-active')).getByText('22')).toBeInTheDocument();
    expect(screen.getByTestId('figure-sold_out')).toHaveTextContent('6');
    expect(screen.queryByTestId('figure-unreleased')).not.toBeInTheDocument(); // rilis mati -> tiga sel
  });

  it('shows six item-type tabs whose counts come from the list response, and "N data" from meta.total', async () => {
    await renderProducts();
    await screen.findByText('Item 1');

    const tabs = screen.getAllByRole('tab');
    expect(tabs.map((tb) => tb.textContent.replace(/\d+$/, '').trim())).toEqual(['Menu item', 'Bahan baku', 'Setengah jadi', 'Kemasan', 'Item ritel', 'Modifier & combo']);
    expect(within(screen.getByRole('tab', { name: /^Menu item/ })).getByText('24')).toBeInTheDocument();
    expect(within(screen.getByRole('tab', { name: /^Bahan baku/ })).getByText('18')).toBeInTheDocument();
    expect(within(screen.getByRole('tab', { name: /^Modifier/ })).getByText('7')).toBeInTheDocument();
    expect(screen.getByTestId('data-count')).toHaveTextContent('24 data');
  });

  // 043 (arahan pemilik) — toolbar daftar: filter di kiri; di kanan berurutan "N data" · Grid|Tabel · "+ Menu item".
  it('right-aligns the count, the Grid | Table switch and the add button in that order, with the filters on the left', async () => {
    await renderProducts();
    await screen.findByText('Item 1');

    const count = screen.getByTestId('data-count');
    const group = count.parentElement;
    const toggle = screen.getByRole('radiogroup', { name: /tampilan daftar/i });
    const add = screen.getByRole('button', { name: 'Menu item' });
    expect(group).toContainElement(toggle);
    expect(group).toContainElement(add);
    expect(group.className).toContain('ml-auto');
    // urutan DOM: hitungan, lalu saklar, lalu tombol tambah
    expect(count.compareDocumentPosition(toggle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(toggle.compareDocumentPosition(add) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // kotak cari dan filter ada di luar kelompok kanan; glyph "+" polos tidak ikut nama aksesibel tombol
    expect(group).not.toContainElement(screen.getByRole('searchbox'));
    expect(add).toHaveTextContent('+ Menu item');
  });

  it('asks the server for 15 per page with the default tab and no variant fan-out', async () => {
    await renderProducts();
    await screen.findByText('Item 1');
    expect(listProducts).toHaveBeenCalledTimes(1);
    expect(listProducts).toHaveBeenCalledWith(expect.objectContaining({ tab: 'menu', page: 1, per_page: 15 }), { skipOutletHeader: true });
    expect(listProducts.mock.calls[0][0]).not.toHaveProperty('with_variants');
  });

  it('search is debounced into the URL and the list query; "reset filters" clears everything', async () => {
    const user = await userEv();
    const { router } = await renderProducts();
    await screen.findByText('Item 1');
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();

    await user.type(screen.getByRole('searchbox'), 'kopi');
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'kopi', page: 1 }), { skipOutletHeader: true }));
    // router.replace resolves asynchronously: the state changes first, the address a tick later.
    await waitFor(() => expect(router.currentRoute.value.query.q).toBe('kopi'));

    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listProducts.mock.lastCall[0].q).toBeUndefined());
    await waitFor(() => expect(router.currentRoute.value.query.q).toBeUndefined());
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });

  it('shows an error state with retry when loading fails, and retry reloads the list', async () => {
    const user = await userEv();
    listProducts.mockRejectedValueOnce(new Error('network'));
    await renderProducts();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Gagal memuat data');
    expect(screen.queryByTestId('data-count')).not.toBeInTheDocument(); // tidak ada angka palsu saat galat

    await user.click(within(alert).getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Item 1')).toBeInTheDocument();
  });

  it('a failed summary does not blank the list: the figures show their own error and retry', async () => {
    const user = await userEv();
    productSummary.mockRejectedValueOnce(new Error('network'));
    await renderProducts();

    expect(await screen.findByText('Item 1')).toBeInTheDocument();
    expect(await screen.findByText('Gagal memuat ringkasan menu')).toBeInTheDocument();
    await user.click(within(screen.getByTestId('product-figures')).getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByTestId('figure-active')).toBeInTheDocument();
  });

  it('opens the create drawer from the header action and from the toolbar button', async () => {
    const user = await userEv();
    await renderProducts();
    await screen.findByText('Item 1');

    await user.click(within(screen.getByRole('heading', { level: 1 }).closest('header')).getByRole('button', { name: /tambah item/i }));
    expect(await screen.findByTestId('product-form')).toBeInTheDocument();
  });

  it('keeps import and export reachable from the one "Impor / ekspor" menu', async () => {
    const user = await userEv();
    const { exportMasterData } = await import('../../resources/js/api/masterData');
    await renderProducts();
    await screen.findByText('Item 1');

    await user.click(screen.getByRole('button', { name: /impor \/ ekspor/i }));
    await user.click(screen.getByRole('menuitem', { name: /ekspor \.xlsx/i }));
    expect(exportMasterData).toHaveBeenCalledWith('products');

    await user.click(screen.getByRole('button', { name: /impor \/ ekspor/i }));
    await user.click(screen.getByRole('menuitem', { name: /impor massal/i }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
  });
});
