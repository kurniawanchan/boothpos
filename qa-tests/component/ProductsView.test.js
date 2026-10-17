import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import id from '../../resources/js/locales/id.json';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listProducts, getProduct, updateProduct, updateVariant, updateVariantSequences, deleteVariant, addVariant, uploadVariantImage } from '../../resources/js/api/products';
import { listArtists } from '../../resources/js/api/artists';
import { listCategories } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(),
  getProduct: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
  deleteProduct: vi.fn(),
  addVariant: vi.fn(),
  updateVariant: vi.fn(),
  updateVariantSequences: vi.fn(),
  deleteVariant: vi.fn(),
  uploadProductImage: vi.fn(),
  uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const PRODUCTS = [
  { id: 1, code_prefix: 'ARTKY001', name: 'Keychain A', artist_name: 'Artist A', category_name: 'Kategori A', is_preorder: false, is_active: true, image_url: 'https://example.test/a.png' },
  { id: 2, code_prefix: 'ARTKY002', name: 'Keychain B', artist_name: 'Artist A', category_name: 'Kategori A', is_preorder: false, is_active: true, image_url: null },
];

function renderProducts({ extraMenus = [] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'products', ...extraMenus] };
  return render(ProductsView, { global: { plugins: [pinia] } });
}

// 004-sidebar-menu-reorg US4/US5, 005-ux-enhancements-dashboard US1
describe('ProductsView — product images & clickable filters', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: PRODUCTS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
  });

  it('renders a thumbnail for a product with an image and a placeholder for one without', async () => {
    renderProducts();
    await screen.findByText('Keychain A');

    const img = screen.getByAltText('Keychain A');
    expect(img).toHaveAttribute('src', 'https://example.test/a.png');
    // Keychain B has no image_url — its row must not render an <img> for it.
    expect(screen.queryByAltText('Keychain B')).not.toBeInTheDocument();
  });

  // 036-bom-variant-stock-ux (US4) — daftar produk yang lebih mudah dibaca.
  it('shows a translated Type header and never a raw translation key', async () => {
    renderProducts();
    await screen.findByText('Keychain A');

    expect(screen.getByRole('columnheader', { name: 'Tipe' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/master_data\.col_type/i);
  });

  // 038 — gambar + kode dalam SATU kolom supaya gambar bisa lebih besar (96px, tadinya 56px).
  it('has no picture-only column: the first column is "Kode" and holds the picture above the code', async () => {
    renderProducts();
    await screen.findByText('Keychain A');

    const headers = screen.getAllByRole('columnheader').map((h) => h.textContent.trim());
    expect(headers.slice(0, 3)).toEqual(['Kode', 'SKU', 'Nama produk']);
    expect(headers.filter((h) => h === '')).toHaveLength(1); // hanya kolom aksi di ujung kanan yang tanpa judul

    const code = screen.getByText('ARTKY001');
    const img = screen.getByAltText('Keychain A');
    const cell = code.closest('td');
    expect(cell).toContainElement(img);
    expect(Boolean(img.compareDocumentPosition(code) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(true); // gambar di atas kode
  });

  it('shows a 96px picture, and a same-size placeholder when there is none', async () => {
    renderProducts();
    await screen.findByText('Keychain A');

    const img = screen.getByAltText('Keychain A');
    expect(img).toHaveClass('h-24', 'w-24');
    expect(img).not.toHaveClass('h-14');
    const placeholder = document.querySelector('.ph-image').parentElement;
    expect(placeholder).toHaveClass('h-24', 'w-24');
    // placeholder juga berada di sel yang sama dengan kodenya
    expect(screen.getByText('ARTKY002').closest('td')).toContainElement(placeholder);
  });

  it('keeps the product code on one line, with the full code available on hover', async () => {
    renderProducts();
    const code = await screen.findByText('ARTKY001');

    expect(code).toHaveClass('whitespace-nowrap');
    expect(code).toHaveAttribute('title', 'ARTKY001');
  });

  it('filters via the searchable artist dropdown and returns to "all" by re-selecting it', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getByText(/semua penjual/i));
    await user.click(await screen.findByRole('option', { name: 'Artist A' }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [1] })));

    // The panel stays open after a selection (multi-select) — pick "All"
    // directly to clear the selection back to unfiltered.
    await user.click(await screen.findByRole('option', { name: /semua penjual/i }));
    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.not.objectContaining({ artist_id: expect.anything() })));
  });

  it('filters via the category dropdown, combinable with the artist filter', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getByText(/semua penjual/i));
    await user.click(await screen.findByRole('option', { name: 'Artist A' }));

    await user.click(screen.getByText(/semua kategori/i));
    await user.click(await screen.findByRole('option', { name: 'Kategori A' }));

    await waitFor(() => expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ artist_id: [1], category_id: [1] })));
  });

  // 024-invoice-layout-shipping-slip — real per-variant SKU column, distinct
  // from the shared code_prefix already shown; fetched via with_variants=1.
  it('requests variants and renders each product\'s variant SKUs, comma-separated', async () => {
    listProducts.mockResolvedValue({
      data: [
        { ...PRODUCTS[0], variants: [{ id: 1, sku: 'ART-KY-001-001' }, { id: 2, sku: 'ART-KY-001-002' }] },
        { ...PRODUCTS[1], variants: [] },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    renderProducts();

    await screen.findByText('Keychain A');
    expect(listProducts).toHaveBeenCalledWith(expect.objectContaining({ with_variants: 1 }));
    expect(screen.getByText('ART-KY-001-001')).toBeInTheDocument();
    expect(screen.getByText('ART-KY-001-002')).toBeInTheDocument();
  });

  // Product code/SKU dashed-format follow-up — SKU column shows only the
  // first 3 variants by default, with a "+N more" toggle for the rest.
  it('shows only the first 3 SKUs by default and expands/collapses via the "+N more" link', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listProducts.mockResolvedValue({
      data: [
        {
          ...PRODUCTS[0],
          variants: [
            { id: 1, sku: 'ART-KY-001-001' },
            { id: 2, sku: 'ART-KY-001-002' },
            { id: 3, sku: 'ART-KY-001-003' },
            { id: 4, sku: 'ART-KY-001-004' },
          ],
        },
        { ...PRODUCTS[1], variants: [] },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    renderProducts();
    await screen.findByText('Keychain A');

    expect(screen.getByText('ART-KY-001-003')).toBeInTheDocument();
    expect(screen.queryByText('ART-KY-001-004')).not.toBeInTheDocument();

    await user.click(screen.getByText('+1 lainnya'));
    expect(screen.getByText('ART-KY-001-004')).toBeInTheDocument();

    await user.click(screen.getByText('Sembunyikan'));
    expect(screen.queryByText('ART-KY-001-004')).not.toBeInTheDocument();
  });

  // Click an individual SKU (not the whole row) — opens VariantDetailModal
  // for just that one variant, distinct from the whole-product "Detail".
  it('opens a single variant detail when its SKU is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listProducts.mockResolvedValue({
      data: [
        {
          ...PRODUCTS[0],
          variants: [{ id: 1, sku: 'ART-KY-001-001', variant_name: 'Standard', sell_price: '20000.00', current_stock: 5, is_active: true, image_url: null }],
        },
        { ...PRODUCTS[1], variants: [] },
      ],
      meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    });
    renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getByText('ART-KY-001-001'));

    const dialogs = screen.getAllByRole('dialog');
    expect(dialogs.some((d) => within(d).queryByText('Standard'))).toBe(true);
  });

  // Product code/SKU dashed-format follow-up — clicking a product thumbnail
  // opens it enlarged in the shared ImageLightbox.
  it('opens the product image in a lightbox when its thumbnail is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getByRole('button', { name: /keychain a/i }));
    const enlarged = await screen.findAllByAltText('Keychain A');
    expect(enlarged.length).toBeGreaterThan(1);
  });

  // Markup (profit ÷ cost) and margin (profit ÷ sell price) are different
  // metrics — shown side by side so the relationship is never mistaken
  // for a bug (a 50% markup is mathematically always a 33% margin).
  it('shows both a markup% and a margin% badge for a variant', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue({
      id: 1, artist_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001',
      description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
      variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', cost_price: '20000.00', sell_price: '30000.00', low_stock_alert: null, is_active: true, image_url: null }],
    });
    renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getAllByText('Edit')[0]);

    await screen.findByDisplayValue('Standard');
    expect(screen.getByText('markup 50%')).toBeInTheDocument();
    expect(screen.getByText('margin 33%')).toBeInTheDocument();
  });

  // Per-variant image — added at the product owner's explicit request so
  // different variants of the same product can each show their own
  // picture instead of all sharing the product's single image.
  it('uploads a chosen file for a variant image when the product is saved', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue({
      id: 1, artist_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001',
      description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
      variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null }],
    });
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
    uploadVariantImage.mockResolvedValue({});

    renderProducts();
    await screen.findByText('Keychain A');
    await user.click(screen.getAllByText('Edit')[0]);
    await screen.findByDisplayValue('Standard');

    // BaseDrawer teleports its content to <body>, outside the render
    // container, so the file inputs must be queried from document instead.
    const fileInputs = document.querySelectorAll('input[type="file"]');
    const variantFileInput = fileInputs[fileInputs.length - 1];
    const file = new File(['x'], 'variant.png', { type: 'image/png' });
    await user.upload(variantFileInput, file);

    await user.click(screen.getByText('Simpan produk'));

    await waitFor(() => expect(uploadVariantImage).toHaveBeenCalledWith(10, file));
  });
});

// 034-seller-po-bom (US4) — varian dengan BOM selesai: harga modal mengikuti BOM.
describe('ProductsView — variants with a completed BOM (034)', () => {
  const PRODUCT = (variant) => ({
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', sell_price: '30000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null, ...variant }],
  });

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: PRODUCTS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
  });

  async function openEditDrawer() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain A');
    await user.click(screen.getAllByText('Edit')[0]);
    await screen.findByDisplayValue('Standard');
  }

  it('locks the cost price and shows a "From BOM" badge when the BOM is complete', async () => {
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '1800.00', bom_complete: true, has_bom: true, bom_cost: '1800.00' }));
    await openEditDrawer();

    const cost = screen.getByDisplayValue('1800.00');
    expect(cost).toBeDisabled();
    expect(screen.getByText('Dari BOM')).toBeInTheDocument();
    expect(screen.getByText(/mengikuti bom yang sudah selesai/i)).toBeInTheDocument();
  });

  it('keeps the cost price editable and shows the BOM cost beside it when the BOM is not complete', async () => {
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '1000.00', bom_complete: false, has_bom: true, bom_cost: '1800.00' }));
    await openEditDrawer();

    expect(screen.getByDisplayValue('1000.00')).not.toBeDisabled();
    expect(screen.queryByText('Dari BOM')).not.toBeInTheDocument();
    expect(screen.getByText('Biaya BOM: Rp 1.800')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Buka BOM' })).toBeInTheDocument();
  });

  it('offers "Open BOM" for a saved variant but not for a variant that has no id yet', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '0.00', bom_complete: false, has_bom: false, bom_cost: null }));
    await openEditDrawer();
    expect(screen.getAllByRole('button', { name: 'Buka BOM' })).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: /tambah varian/i }));
    expect(screen.getAllByRole('button', { name: 'Buka BOM' })).toHaveLength(1);
  });

  it('lets a new variant start empty or copy the BOM of a saved variant, and sends the choice on save', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '1800.00', bom_complete: false, has_bom: true, bom_cost: '1800.00' }));
    addVariant.mockResolvedValue({ id: 11 });
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
    renderProducts({ extraMenus: ['purchase_orders'] });
    await screen.findByText('Keychain A');
    await user.click(screen.getAllByText('Edit')[0]);
    await screen.findByDisplayValue('Standard');

    await user.click(screen.getByRole('button', { name: /tambah varian/i }));
    await user.click(await screen.findByRole('combobox', { name: /bom untuk varian baru ini/i }));
    await user.click(await screen.findByRole('option', { name: /salin bom dari ART-KY-001-001/i }));
    await user.click(screen.getByText('Simpan produk'));

    await waitFor(() => expect(addVariant).toHaveBeenCalled());
    expect(addVariant.mock.calls[0][1]).toMatchObject({ copy_bom_from_variant_id: 10 });
  });

  it('does not offer the BOM choice for a new variant when no saved variant has a BOM', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '0.00', bom_complete: false, has_bom: false, bom_cost: null }));
    renderProducts({ extraMenus: ['purchase_orders'] });
    await screen.findByText('Keychain A');
    await user.click(screen.getAllByText('Edit')[0]);
    await screen.findByDisplayValue('Standard');

    await user.click(screen.getByRole('button', { name: /tambah varian/i }));

    expect(screen.queryByText('BOM untuk varian baru ini')).not.toBeInTheDocument();
  });
});

// 038-product-list-image-sku-tooltip (US2) — nama varian muncul saat SKU di-hover / difokus.
describe('ProductsView — SKU tooltip shows the variant name (038)', () => {
  const V = (n, name) => ({ id: 100 + n, sku: `VLC-SK-ARE-00${n}`, variant_name: name, sell_price: '20000.00', current_stock: 5, is_active: true, image_url: null });
  const WITH_VARIANTS = [
    {
      id: 7, code_prefix: 'VLC-SK-ARE', name: 'Arekku', artist_name: 'Artist A', category_name: 'Kategori A', is_preorder: false, is_active: true, image_url: null,
      variants: [V(1, 'Merah'), V(2, 'Biru'), V(3, 'Hijau'), V(4, 'Kuning'), V(5, 'Biru'), V(6, '')],
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: WITH_VARIANTS, meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
  });

  async function setup() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Arekku');

    return user;
  }
  const sku = (n) => screen.getByRole('button', { name: `VLC-SK-ARE-00${n}` });
  const showMore = () => screen.getByRole('button', { name: new RegExp(`^${id.master_data.show_more_count.replace('{count}', '3').replace(/[+]/g, '\\+')}`) });

  it('shows that variant\'s name when the SKU is hovered and hides it when the pointer leaves', async () => {
    const user = await setup();

    await user.hover(sku(2));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Biru');
    await user.unhover(sku(2));
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();

    await user.hover(sku(1));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Merah');
  });

  it('shows the same tooltip on keyboard focus and Escape hides it', async () => {
    const user = await setup();

    await user.tab(); // fokus ke tombol pertama pada halaman; arahkan ke SKU secara eksplisit
    sku(3).focus();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Hijau');

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('gives SKUs revealed by "+N more" their own variant name, and tells same-named variants apart by SKU', async () => {
    const user = await setup();
    await user.click(showMore());

    await user.hover(sku(4));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Kuning');
    await user.unhover(sku(4));

    await user.hover(sku(2));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Biru');
    await user.unhover(sku(2));
    await user.hover(sku(5));
    expect(screen.getByRole('tooltip')).toHaveTextContent('Biru'); // nama sama, tetapi SKU tetap tampil & berbeda
    expect(sku(2)).not.toBe(sku(5));
  });

  it('still opens that variant\'s detail when the SKU is clicked while its tooltip is showing', async () => {
    const user = await setup();

    await user.hover(sku(2));
    expect(screen.getByRole('tooltip')).toBeVisible();
    await user.click(sku(2));

    expect(await screen.findByRole('dialog', { name: 'VLC-SK-ARE-002' })).toBeInTheDocument();
  });

  it('gives the "+N more" toggle no tooltip, and a variant without a name none either', async () => {
    const user = await setup();

    await user.hover(showMore());
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    await user.click(showMore()); // membuka SKU yang tersembunyi (tombol berubah menjadi "lebih sedikit")

    await user.hover(sku(6)); // variant_name = ''
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });
});


// Spec 044 (US3, FR-006/FR-007): segmen yang diubah dikirim ke server; penolakan
// (422 pada product_segment) tampil di bawah kolom segmen, bukan diam-diam hilang.
describe('ProductsView — changing the segment (044)', () => {
  const PRODUCT_SH = {
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KA-SH', product_segment: 'SH',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [{ id: 10, sku: 'ART-KA-SH-1', variant_name: 'Standard', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null }],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [PRODUCT_SH], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
  });

  it('sends the new segment when the product is saved', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue(PRODUCT_SH);
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });

    renderProducts();
    await screen.findByText('Keychain A');
    await user.click(screen.getAllByText('Edit')[0]);
    const segment = await screen.findByDisplayValue('SH');
    await user.clear(segment);
    await user.type(segment, 'S');
    await user.click(screen.getByText('Simpan produk'));

    await waitFor(() => expect(updateProduct).toHaveBeenCalledWith(1, expect.objectContaining({ product_segment: 'S' })));
  });

  it('shows the server refusal of a segment under the segment field', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue(PRODUCT_SH);
    updateProduct.mockRejectedValue(Object.assign(new Error('validation'), {
      isValidation: true,
      errors: { product_segment: ['Kombinasi kode ini (ART-KA-S) sudah dipakai produk lain.'] },
    }));

    renderProducts();
    await screen.findByText('Keychain A');
    await user.click(screen.getAllByText('Edit')[0]);
    const segment = await screen.findByDisplayValue('SH');
    await user.clear(segment);
    await user.type(segment, 'S');
    await user.click(screen.getByText('Simpan produk'));

    expect(await screen.findByText('Kombinasi kode ini (ART-KA-S) sudah dipakai produk lain.')).toBeInTheDocument();
  });
});

// Spec 044 US4 (FR-008/FR-009/FR-010): form edit menampilkan segmen tersimpan dan
// pratinjau memakai kode tersimpan; menyimpan tanpa perubahan mengirim nilai yang sama.
describe('ProductsView — saved segment in the edit form (044 US4)', () => {
  const PRODUCT_S = {
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain S', code_prefix: 'ART-KA-S', product_segment: 'S',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [{ id: 10, sku: 'ART-KA-S-1', variant_name: 'Standard', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null }],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [PRODUCT_S], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    getProduct.mockResolvedValue(PRODUCT_S);
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
  });

  it('shows the saved segment and previews the saved code when the product is edited', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    renderProducts();
    await screen.findByText('Keychain S');
    await user.click(screen.getAllByText('Edit')[0]);

    expect(await screen.findByDisplayValue('S')).toBeInTheDocument();
    expect(document.body.textContent).toContain('ART-KA-S-1');
  });

  it('sends the unchanged saved segment when the product is saved', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    renderProducts();
    await screen.findByText('Keychain S');
    await user.click(screen.getAllByText('Edit')[0]);
    await screen.findByDisplayValue('S');
    await user.click(screen.getByText('Simpan produk'));

    await waitFor(() => expect(updateProduct).toHaveBeenCalledWith(1, expect.objectContaining({ product_segment: 'S' })));
  });
});

// Spec 045 (US1): kode produk tetap; nomor urut diketik dan dikirim; penolakan SKU
// tampil di kolom nomor varian itu.
describe('ProductsView — typed variant sequence (045)', () => {
  const PRODUCT_VAR = {
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain V', code_prefix: 'ART-KA-S', product_segment: 'S',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [{ id: 10, sku: 'ART-KA-S-1', variant_name: 'Standard', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null }],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [PRODUCT_VAR], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    getProduct.mockResolvedValue(PRODUCT_VAR);
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
  });

  it('shows the fixed code as text and the sequence number as an editable field', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    renderProducts();
    await screen.findByText('Keychain V');
    await user.click(screen.getAllByText('Edit')[0]);

    const field = await screen.findByTestId('variant-sequence');
    expect(field).toHaveValue('1');
    expect(screen.getByTestId('variant-sku')).toHaveTextContent('ART-KA-S-');
  });

  it('sends the typed number when the product is saved', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    renderProducts();
    await screen.findByText('Keychain V');
    await user.click(screen.getAllByText('Edit')[0]);
    const field = await screen.findByTestId('variant-sequence');
    await user.clear(field);
    await user.type(field, '7');
    await user.click(screen.getByText('Simpan produk'));

    await waitFor(() => expect(updateVariantSequences).toHaveBeenCalledWith(1, [{ variant_id: 10, sequence: '7' }]));
  });

  it('shows a refused SKU under that variant\'s number field', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updateVariantSequences.mockRejectedValue(Object.assign(new Error('validation'), {
      isValidation: true,
      errors: { 'sequences.10': ['Nomor SKU ART-KA-S-7 sudah dipakai.'] },
    }));

    renderProducts();
    await screen.findByText('Keychain V');
    await user.click(screen.getAllByText('Edit')[0]);
    const field = await screen.findByTestId('variant-sequence');
    await user.clear(field);
    await user.type(field, '7');
    await user.click(screen.getByText('Simpan produk'));

    expect(await screen.findByText('Nomor SKU ART-KA-S-7 sudah dipakai.')).toBeInTheDocument();
  });
});

// Spec 045 (geser nomor): dua varian digeser sekaligus (10→11, 11→12) dikirim dalam SATU permintaan.
describe('ProductsView — shifting variant numbers together (045)', () => {
  const PRODUCT_SHIFT = {
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain G', code_prefix: 'ART-KA-S', product_segment: 'S',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [
      { id: 10, sku: 'ART-KA-S-10', variant_name: 'Sepuluh', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null },
      { id: 11, sku: 'ART-KA-S-11', variant_name: 'Sebelas', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [PRODUCT_SHIFT], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    getProduct.mockResolvedValue(PRODUCT_SHIFT);
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
    updateVariantSequences.mockResolvedValue({});
  });

  it('sends both shifted numbers in one request', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    renderProducts();
    await screen.findByText('Keychain G');
    await user.click(screen.getAllByText('Edit')[0]);
    const [first, second] = await screen.findAllByTestId('variant-sequence');
    await user.clear(first);
    await user.type(first, '11');
    await user.clear(second);
    await user.type(second, '12');
    await user.click(screen.getByText('Simpan produk'));

    await waitFor(() => expect(updateVariantSequences).toHaveBeenCalledTimes(1));
    expect(updateVariantSequences).toHaveBeenCalledWith(1, [
      { variant_id: 10, sequence: '11' },
      { variant_id: 11, sequence: '12' },
    ]);
  });
});

// Spec 045 US4 (FR-012/FR-014): varian tersimpan urut SKU di formulir; baris yang
// belum disimpan tetap di posisi tambahnya.
describe('ProductsView — variant cards in SKU order (045 US4)', () => {
  const variant = (id, sku) => ({ id, sku, variant_name: sku, cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null });
  const PRODUCT_ORDER = {
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain O', code_prefix: 'ART-KA-S', product_segment: 'S',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [variant(10, 'ART-KA-S-10'), variant(12, 'ART-KA-S-2'), variant(11, 'ART-KA-S-1')],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [PRODUCT_ORDER], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    getProduct.mockResolvedValue(PRODUCT_ORDER);
  });

  it('shows saved variants in SKU order, numbers as whole numbers', async () => {
    renderProducts();
    await screen.findByText('Keychain O');
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.click(screen.getAllByText('Edit')[0]);

    await screen.findAllByTestId('variant-sequence');
    expect(screen.getAllByTestId('variant-sequence').map((el) => el.value)).toEqual(['1', '2', '10']);
  });

  it('keeps an unsaved added variant at the end until the product is reopened', async () => {
    renderProducts();
    await screen.findByText('Keychain O');
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.click(screen.getAllByText('Edit')[0]);
    await screen.findAllByTestId('variant-sequence');

    await user.click(screen.getByRole('button', { name: /add variant|tambah varian/i }));

    const values = screen.getAllByTestId('variant-sequence').map((el) => el.value);
    expect(values.slice(0, 3)).toEqual(['1', '2', '10']);
    expect(values.length).toBe(4);
  });
});

// Spec 045 FR-015: tombol hapus pada varian tersimpan. Tanpa riwayat → hilang dari
// formulir; dengan riwayat → tetap ada, dinonaktifkan.
describe('ProductsView — removing a saved variant (045 FR-015)', () => {
  const PRODUCT_DEL = {
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain D', code_prefix: 'ART-KA-S', product_segment: 'S',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [
      { id: 10, sku: 'ART-KA-S-1', variant_name: 'Satu', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null },
      { id: 11, sku: 'ART-KA-S-25', variant_name: 'Dua Puluh Lima', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [PRODUCT_DEL], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    getProduct.mockResolvedValue(PRODUCT_DEL);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });

  it('removes a variant without history from the form', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deleteVariant.mockResolvedValue({ removed: true });

    renderProducts();
    await screen.findByText('Keychain D');
    await user.click(screen.getAllByText('Edit')[0]);
    await screen.findAllByTestId('variant-sequence');

    // Tombol hapus varian pertama = kartu pertama (varian ART-KA-S-1).
    await user.click(screen.getAllByRole('button', { name: /delete variant|hapus varian/i })[0]);

    await waitFor(() => expect(deleteVariant).toHaveBeenCalledWith(10));
    await waitFor(() => expect(screen.getAllByTestId('variant-sequence')).toHaveLength(1));
  });
});

// Spec 045 FR-017: varian nonaktif tidak tampil di formulir dan tidak menahan nomornya.
describe('ProductsView — deactivated variants are hidden (045 FR-017)', () => {
  const PRODUCT_HIDE = {
    id: 1, artist_id: 1, category_id: 1, name: 'Keychain H', code_prefix: 'ART-KA-S', product_segment: 'S',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [
      { id: 10, sku: 'ART-KA-S-1', variant_name: 'Aktif', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null },
      { id: 11, sku: 'ART-KA-S-25', variant_name: 'Tersembunyi', cost_price: '10000.00', sell_price: '20000.00', current_stock: 0, low_stock_alert: null, is_active: false, image_url: null },
    ],
  };

  it('does not list a deactivated variant in the edit form', async () => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [PRODUCT_HIDE], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    getProduct.mockResolvedValue(PRODUCT_HIDE);

    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    await screen.findByText('Keychain H');
    await user.click(screen.getAllByText('Edit')[0]);

    const numbers = (await screen.findAllByTestId('variant-sequence')).map((el) => el.value);
    expect(numbers).toEqual(['1']);
  });
});
