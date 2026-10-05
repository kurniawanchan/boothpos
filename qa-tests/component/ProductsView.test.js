import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listProducts, getProduct, updateProduct, updateVariant, addVariant, uploadVariantImage } from '../../resources/js/api/products';
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

  it('shows larger thumbnails (image and placeholder share the same 56px box)', async () => {
    renderProducts();
    await screen.findByText('Keychain A');

    const img = screen.getByAltText('Keychain A');
    expect(img).toHaveClass('h-14', 'w-14');
    expect(img).not.toHaveClass('h-9');
    const placeholder = document.querySelector('.ph-image').parentElement;
    expect(placeholder).toHaveClass('h-14', 'w-14');
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

