import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import ProductDetailView from '../../resources/js/views/ProductDetailView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { getProduct, priceHistory, undoSoldOut, reactivateProduct } from '../../resources/js/api/products';

vi.mock('../../resources/js/api/products', () => ({
  getProduct: vi.fn(), priceHistory: vi.fn(), soldOut: vi.fn(), undoSoldOut: vi.fn(), discontinueProduct: vi.fn(), reactivateProduct: vi.fn(),
  listProducts: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), deleteProduct: vi.fn(), addVariant: vi.fn(), updateVariant: vi.fn(),
  uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
// 043 (US8) — bagian Resep memuat daftar versi sendiri; tanpa mock-nya permintaan jatuh ke jaringan sungguhan.
vi.mock('../../resources/js/api/recipes', () => ({ listRecipes: vi.fn().mockResolvedValue({ data: [] }), getRecipe: vi.fn(), createRecipe: vi.fn(), recalculateRecipeCost: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { last_page: 1 } }), getMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/stock', () => ({ createAdjustment: vi.fn() }));
vi.mock('../../resources/js/api/reasons', () => ({ listReasonCodes: vi.fn().mockResolvedValue({ data: [{ id: 1, label: 'Bahan habis' }] }) }));

const ALL_CAN = { edit: true, mark_86: true, discontinue: true, bulk_price: false, edit_recipe: false };

const PRODUCT = (extra = {}) => ({
  id: 12, code_prefix: 'KOP-001', name: 'Kopi Tubruk', category_name: 'Kopi', status: 'active', short_name: 'Tubruk',
  tax_category: 'restaurant_food_beverage', prep_minutes: 3, station: { id: 1, name: 'Bar', source: 'category' }, release: null,
  variants: [
    { id: 1, sku: 'KOP-001-1', variant_name: 'Panas', sell_price: '8000.00', is_active: true },
    { id: 2, sku: 'KOP-001-2', variant_name: 'Es', sell_price: '10000.00', is_active: true },
  ],
  modifier_links: [
    { group_id: 5, name: 'Gula', is_required: false, min_select: 0, max_select: 1, display_order: 1, options: ['Tanpa', 'Normal'] },
    { group_id: 6, name: 'Ukuran', is_required: true, min_select: 1, max_select: 2, display_order: 2, options: ['S', 'L'] },
  ],
  availability: [{ outlet_id: null, state: 'available', reason: null, daily_quota: null, channels: null }],
  recipe: null, can: ALL_CAN, ...extra,
});

const HISTORY = {
  data: [
    { at: '2026-10-01T09:00:00+07:00', variant: 'Panas', old_price: '7000.00', new_price: '8000.00', source: 'release', by: 'Laila', approved_by: 'Hendra', release_version: 42, note: null },
    { at: '2026-09-01T09:00:00+07:00', variant: 'Panas', old_price: null, new_price: '7000.00', source: 'created', by: 'Owner', approved_by: null, release_version: null, note: null },
  ],
  meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
};

async function renderPage({ history = ['/products?view=table&page=2', '/products/12'] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'products'], permissions: [] };
  useSettingsStore().menuReleaseEnabled = false;
  // Riwayat WEB (jsdom), bukan memori: tombol Kembali membaca `history.state.back`, yang hanya diisi riwayat web.
  window.history.replaceState(null, '', history[0]);
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/products', name: 'products', component: { template: '<div data-testid="list-page" />' } },
      { path: '/products/:id(\\d+)', name: 'product-detail', component: ProductDetailView },
    ],
  });
  for (const url of history) await router.push(url);
  await router.isReady();
  const view = render({ template: '<RouterView />' }, { global: { plugins: [pinia, router] } });
  return { ...view, router };
}

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T038) — halaman satu item.
describe('ProductDetailView (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getProduct.mockResolvedValue(PRODUCT());
    priceHistory.mockResolvedValue(HISTORY);
  });

  it('shows the header card: kicker with the code, name, status, category and the facts grid', async () => {
    await renderPage();
    expect(await screen.findByRole('heading', { level: 1, name: 'Kopi Tubruk' })).toBeInTheDocument();
    // 2026-10-31 (permintaan pemilik): kode pindah ke BAWAH nama, satu tingkat lebih kecil; kicker hanya jenis item.
    expect(screen.getByTestId('item-kicker')).toHaveTextContent(/^Menu item$/);
    expect(screen.getByTestId('item-code')).toHaveTextContent('KOP-001');
    expect(screen.getByTestId('item-code').compareDocumentPosition(screen.getByRole('heading', { level: 1 })) & Node.DOCUMENT_POSITION_PRECEDING).toBeTruthy();
    expect(screen.getByTestId('fact-stock_source')).toHaveTextContent('Varian');
    expect(screen.getByTestId('fact-status')).toHaveTextContent('Aktif');
    expect(screen.getByTestId('status-badge')).toHaveTextContent('Aktif');
    expect(screen.getByTestId('item-badges')).toHaveTextContent('Kopi');
    expect(screen.getByTestId('fact-short')).toHaveTextContent('Tubruk');
    expect(screen.getByTestId('fact-station')).toHaveTextContent('Bar');
    expect(screen.getByTestId('fact-station')).toHaveTextContent('mengikuti kategori');
    expect(screen.getByTestId('fact-prep')).toHaveTextContent('3 menit');
    expect(screen.getByTestId('fact-price')).toHaveTextContent('8.000');
    expect(screen.getByTestId('fact-price')).toHaveTextContent('10.000');
    expect(screen.queryByTestId('fact-release')).not.toBeInTheDocument();
  });

  it('shows the product image in the header, or a placeholder without one (2026-10-31)', async () => {
    getProduct.mockResolvedValue(PRODUCT({ image_url: 'https://example.test/kopi.png', stock_source: 'modifier' }));
    await renderPage();
    expect(await screen.findByTestId('item-image')).toHaveAttribute('src', 'https://example.test/kopi.png');
    expect(screen.getByTestId('fact-stock_source')).toHaveTextContent('Modifier');
  });

  it('renders every action only when the matching `can` flag is true (hidden, not disabled)', async () => {
    getProduct.mockResolvedValue(PRODUCT({ can: { edit: false, mark_86: false, discontinue: false, bulk_price: false, edit_recipe: false } }));
    await renderPage();
    await screen.findByRole('heading', { level: 1, name: 'Kopi Tubruk' });
    expect(screen.queryByTestId('item-actions')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /edit item/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /tandai 86/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^discontinue$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /kelola varian/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /kelola modifier/i })).not.toBeInTheDocument();
  });

  // 043 (T062/T073) — "Kelola varian" dan "Kelola modifier" membuka laci masing-masing; formulir item panjang tidak ikut terbuka.
  it('"Kelola varian" opens the variants drawer and "Kelola modifier" the modifier links drawer', async () => {
    const user = await userEv();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: /kelola varian/i }));
    expect(await screen.findByRole('dialog', { name: /varian & harga/i })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^batal$/i }));
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /varian & harga/i })).not.toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /kelola modifier/i }));
    expect(await screen.findByRole('dialog', { name: /grup modifier/i })).toBeInTheDocument();
  });

  it('offers only the actions allowed: 86 without discontinue, and the reverse', async () => {
    getProduct.mockResolvedValue(PRODUCT({ can: { ...ALL_CAN, edit: false, discontinue: false } }));
    const first = await renderPage();
    await screen.findByRole('button', { name: /tandai 86/i });
    expect(screen.queryByRole('button', { name: /edit item/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^discontinue$/i })).not.toBeInTheDocument();
    first.unmount();

    getProduct.mockResolvedValue(PRODUCT({ can: { ...ALL_CAN, mark_86: false } }));
    await renderPage();
    await screen.findByRole('button', { name: /^discontinue$/i });
    expect(screen.queryByRole('button', { name: /tandai 86/i })).not.toBeInTheDocument();
  });

  it('lists variants with SKU and price, never a cost column', async () => {
    await renderPage();
    const rows = await screen.findAllByTestId('variant-row');
    expect(rows).toHaveLength(2);
    expect(within(rows[0]).getByText('Panas')).toBeInTheDocument();
    expect(within(rows[0]).getByText('KOP-001-1')).toBeInTheDocument();
    expect(screen.queryByText(/modal|cost/i, { selector: 'th' })).not.toBeInTheDocument();
  });

  // 043 (arahan pemilik) — gaya tabel desain: kepala kalimat biasa, SKU teks polos, harga dasar tebal rata kanan, pil Aktif.
  it('styles the section tables like the design: sentence-case header, plain SKU, bold right-aligned base price, status pill', async () => {
    await renderPage();
    const rows = await screen.findAllByTestId('variant-row');
    const table = rows[0].closest('table');
    const headers = within(table).getAllByRole('columnheader');
    expect(headers.map((h) => h.textContent.trim())).toEqual(['Varian', 'SKU', 'Harga dasar', 'Status']);
    expect(table.className).not.toMatch(/uppercase/);
    expect(headers[2]).toHaveClass('text-right');

    const cells = within(rows[0]).getAllByRole('cell');
    expect(cells[0]).toHaveClass('font-semibold');
    expect(cells[1]).not.toHaveClass('font-mono');
    expect(cells[1]).not.toHaveClass('text-brand-active');
    expect(cells[2]).toHaveClass('text-right', 'font-bold');
    expect(within(cells[3]).getByText('Aktif')).toHaveClass('rounded-full');

    // semua bagian bertabel memakai gaya yang sama (satu komponen SectionTable), bukan salinan masing-masing
    for (const id of ['section-variants', 'section-modifiers', 'section-availability']) {
      expect(within(screen.getByTestId(id)).getByRole('table').className).toContain('[&_td]:px-4');
    }
  });

  it('writes the modifier rule as "Opsional · 0–1" / "Wajib · 1–2"', async () => {
    await renderPage();
    const rules = await screen.findAllByTestId('modifier-rule');
    expect(rules.map((r) => r.textContent.trim())).toEqual(['Opsional · 0–1', 'Wajib · 1–2']);
  });

  it('shows the 86 badge and the reason, who and until-when note when the item is sold out', async () => {
    getProduct.mockResolvedValue(PRODUCT({
      availability: [{ outlet_id: null, state: 'sold_out', reason: 'Bahan habis', note: 'susu', marked_by: 'Laila', expires_at: '2026-10-11T23:59:00+07:00', daily_quota: null, channels: null }],
    }));
    await renderPage();
    expect(await screen.findByTestId('badge-86')).toHaveTextContent('86');
    const note = screen.getByTestId('sold-out-note');
    expect(note).toHaveTextContent('Bahan habis');
    expect(note).toHaveTextContent('Laila');
    expect(note).toHaveTextContent('susu');
    // item 86: tombol batal 86 menggantikan tombol tandai 86
    expect(screen.getByRole('button', { name: /batalkan 86/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^tandai 86$/i })).not.toBeInTheDocument();
  });

  it('summarises a legacy BOM, and says "Belum ada resep" when there is none', async () => {
    getProduct.mockResolvedValue(PRODUCT({
      variants: [{ id: 1, sku: 'A', variant_name: 'Panas', sell_price: '8000.00', is_active: true, has_bom: true, bom_cost: '3000.00' }],
    }));
    const first = await renderPage();
    expect(await screen.findByTestId('bom-summary')).toHaveTextContent(/1 varian memakai BOM/);
    expect(screen.getByTestId('bom-summary')).toHaveTextContent('3.000');
    expect(screen.queryByRole('button', { name: /edit resep/i })).not.toBeInTheDocument();
    first.unmount();

    getProduct.mockResolvedValue(PRODUCT());
    await renderPage();
    expect(await screen.findByTestId('recipe-empty')).toHaveTextContent('Belum ada resep');
  });

  it('shows food cost and gross margin when a recipe exists', async () => {
    getProduct.mockResolvedValue(PRODUCT({ recipe: { version: 3, portion_cost: '3100.00', food_cost_pct: 38.7, gross_margin: '4900.00', lines: [] } }));
    await renderPage();
    const summary = await screen.findByTestId('recipe-summary');
    expect(summary).toHaveTextContent('38.7%');
    expect(summary).toHaveTextContent('4.900');
  });

  it('renders the price history newest first with who/approved/release, ending in "Item dibuat"', async () => {
    await renderPage();
    const entries = await screen.findAllByTestId('history-entry');
    expect(entries).toHaveLength(2);
    expect(entries[0]).toHaveTextContent('Harga dasar');
    expect(entries[0]).toHaveTextContent('8.000');
    expect(entries[0]).toHaveTextContent('Diajukan Laila · disetujui Hendra · rilis v42');
    expect(entries[1]).toHaveTextContent('Item dibuat');
  });

  it('an edit that applied immediately says "Diubah oleh", not "Diajukan"', async () => {
    priceHistory.mockResolvedValue({
      data: [{ at: '2026-10-11T09:00:00+00:00', variant: null, old_price: '7000.00', new_price: '8000.00', source: 'edit', by: 'Laila', approved_by: null, release_version: null, note: null }],
      meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
    });
    await renderPage();
    const [entry] = await screen.findAllByTestId('history-entry');
    expect(entry).toHaveTextContent('Diubah oleh Laila');
    expect(entry).not.toHaveTextContent('Diajukan');
  });

  it('a failing price history only replaces its own section with an error + Retry; the rest stays', async () => {
    priceHistory.mockRejectedValueOnce(new Error('boom'));
    const user = await userEv();
    await renderPage();
    const section = await screen.findByTestId('section-history');
    expect(await within(section).findByRole('alert')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'Kopi Tubruk' })).toBeInTheDocument();
    expect(screen.getAllByTestId('variant-row')).toHaveLength(2);

    await user.click(within(section).getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findAllByTestId('history-entry')).toHaveLength(2);
  });

  it('a failing core request shows a page error with Retry, and Retry recovers', async () => {
    getProduct.mockRejectedValueOnce(new Error('boom'));
    const user = await userEv();
    await renderPage();
    const retry = await screen.findByRole('button', { name: /coba lagi/i });
    await user.click(retry);
    expect(await screen.findByRole('heading', { level: 1, name: 'Kopi Tubruk' })).toBeInTheDocument();
  });

  it('loads more history pages when meta says there is another page', async () => {
    priceHistory.mockResolvedValueOnce({ data: HISTORY.data.slice(0, 1), meta: { current_page: 1, per_page: 1, total: 2, last_page: 2 } });
    priceHistory.mockResolvedValueOnce({ data: HISTORY.data.slice(1), meta: { current_page: 2, per_page: 1, total: 2, last_page: 2 } });
    const user = await userEv();
    await renderPage();
    expect(await screen.findAllByTestId('history-entry')).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: /muat lebih banyak/i }));
    await waitFor(() => expect(screen.getAllByTestId('history-entry')).toHaveLength(2));
    expect(priceHistory).toHaveBeenLastCalledWith(12, { page: 2 });
    expect(screen.queryByRole('button', { name: /muat lebih banyak/i })).not.toBeInTheDocument();
  });

  it('back link returns to the previous list URL (query intact) when the list was the previous page', async () => {
    const user = await userEv();
    const { router } = await renderPage();
    await user.click(await screen.findByTestId('back-link'));
    await waitFor(() => expect(router.currentRoute.value.name).toBe('products'));
    expect(router.currentRoute.value.fullPath).toBe('/products?view=table&page=2');
  });

  it('back link goes to the plain list when the page was opened directly', async () => {
    const user = await userEv();
    const { router } = await renderPage({ history: ['/products/12'] });
    await user.click(await screen.findByTestId('back-link'));
    await waitFor(() => expect(router.currentRoute.value.name).toBe('products'));
    expect(router.currentRoute.value.fullPath).toBe('/products');
  });

  it('undo 86 asks for confirmation, then calls DELETE and reloads', async () => {
    getProduct.mockResolvedValueOnce(PRODUCT({ availability: [{ outlet_id: null, state: 'sold_out', reason: 'Bahan habis', marked_by: 'Laila', expires_at: null }] }));
    getProduct.mockResolvedValue(PRODUCT());
    undoSoldOut.mockResolvedValue({});
    const user = await userEv();
    await renderPage();
    await user.click(await screen.findByRole('button', { name: /batalkan 86/i }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /batalkan 86/i }));
    await waitFor(() => expect(undoSoldOut).toHaveBeenCalledWith(12));
    await waitFor(() => expect(screen.queryByTestId('badge-86')).not.toBeInTheDocument());
  });

  it('reactivate (discontinued item) confirms, then calls the API; with a pending change the badge is NOT flipped', async () => {
    getProduct.mockResolvedValue(PRODUCT({ status: 'discontinued' }));
    reactivateProduct.mockResolvedValue({ id: 12, status: 'discontinued', change: { id: 9, kind: 'item_reactivate', status: 'pending' } });
    const user = await userEv();
    await renderPage();
    expect(screen.queryByRole('button', { name: /tandai 86/i })).not.toBeInTheDocument(); // item diskontinu tidak bisa 86
    await user.click(await screen.findByRole('button', { name: /aktifkan lagi/i }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /aktifkan lagi/i }));
    await waitFor(() => expect(reactivateProduct).toHaveBeenCalledWith(12));
    expect(await screen.findByTestId('pending-notice')).toHaveTextContent('menunggu rilis menu berikutnya');
    expect(screen.getByTestId('status-badge')).toHaveTextContent('Discontinued');
  });
});
