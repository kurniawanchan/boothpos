import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import { renderDrawerHost } from './productFormDrawerHost';
import { listProducts, productSummary, getProduct, createProduct, updateProduct, updateVariant, addVariant, uploadVariantImage } from '../../resources/js/api/products';
import { listOutlets } from '../../resources/js/api/outlets';
import { listCategories } from '../../resources/js/api/categories';
import { ROW, COUNTS, SUMMARY, META, renderProducts as renderProductsPage } from './productsViewHarness';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(),
  productSummary: vi.fn(),
  getProduct: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
  deleteProduct: vi.fn(),
  addVariant: vi.fn(),
  updateVariant: vi.fn(),
  uploadProductImage: vi.fn(),
  uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } }), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

// Fitur 043: daftar tidak lagi membuka laci UBAH (Edit di Tabel/Grid menautkan ke halaman item; lihat
// ProductsViewTable/ProductsViewGrid). Laci ubah dibuka dari tombol "Edit item" halaman item (ProductDetailView), jadi
// tes perilaku laci di bawah memasangnya lewat inang kecil yang meniru pemicu itu (tombol "Ubah <nama>" -> openEdit);
// seluruh perilaku laci (varian, BOM, gambar varian, stok) tidak berubah. Alur TAMBAH tetap diuji lewat halaman daftar.
// Struktur daftar baru ada di ProductsPageStructure / ProductsViewGrid / ProductsViewTable; sel SKU dan kode lama
// (tooltip, "+N lainnya", gambar 96px) kini diuji langsung di ProductSkuCell.test.js dan ProductCodeCell.test.js.
function renderProducts({ extraMenus = [] } = {}) {
  return renderProductsPage({ menus: ['dashboard', 'products', ...extraMenus], url: '/products?view=table' });
}

function renderEditHost({ extraMenus = [] } = {}) {
  return renderDrawerHost({ menus: ['dashboard', 'products', ...extraMenus], product: { id: 1, name: 'Keychain A' } });
}

const editButton = () => screen.getAllByRole('button', { name: /^ubah /i })[0];

function seedList() {
  vi.clearAllMocks();
  localStorage.clear();
  listProducts.mockResolvedValue({ data: [ROW(1, { name: 'Keychain A' }), ROW(2, { name: 'Keychain B' })], meta: META(2), counts: COUNTS });
  productSummary.mockResolvedValue(SUMMARY);
  listOutlets.mockResolvedValue({ data: [{ id: 1, name: 'Outlet A', code: 'ART' }] });
  listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
}

// Drawer edit produk (tidak berubah sejak 004/034/037; kini dipicu dari halaman item, di sini lewat inang).
describe('ProductsView — edit drawer (variants, markup, variant image)', () => {
  beforeEach(seedList);

  it('shows both a markup% and a margin% badge for a variant', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue({
      id: 1, home_outlet_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001',
      description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
      variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', cost_price: '20000.00', sell_price: '30000.00', low_stock_alert: null, is_active: true, image_url: null }],
    });
    await renderEditHost();
    await screen.findByText('Keychain A');

    await user.click(editButton());

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
      id: 1, home_outlet_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001',
      description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
      variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', cost_price: '10000.00', sell_price: '20000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null }],
    });
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
    uploadVariantImage.mockResolvedValue({});

    await renderEditHost();
    await screen.findByText('Keychain A');
    await user.click(editButton());
    await screen.findByDisplayValue('Standard');

    // BaseDrawer teleports its content to <body>, outside the render
    // container, so the file inputs must be queried from document instead.
    const variantFileInput = screen.getAllByTestId('variant-row')[0].querySelector('input[type="file"]');
    const file = new File(['x'], 'variant.png', { type: 'image/png' });
    await user.upload(variantFileInput, file);

    await user.click(screen.getByText('Simpan item'));

    await waitFor(() => expect(uploadVariantImage).toHaveBeenCalledWith(10, file));
  });
});

// 034-seller-po-bom (US4) — varian dengan BOM selesai: harga modal mengikuti BOM.
describe('ProductsView — variants with a completed BOM (034)', () => {
  const PRODUCT = (variant) => ({
    id: 1, home_outlet_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', sell_price: '30000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null, ...variant }],
  });

  beforeEach(seedList);

  async function openEditDrawer() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderEditHost();
    await screen.findByText('Keychain A');
    await user.click(editButton());
    await screen.findByDisplayValue('Standard');
  }

  it('locks the cost price and shows a "From BOM" badge when the BOM is complete', async () => {
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '1800.00', bom_complete: true, has_bom: true, bom_cost: '1800.00' }));
    await openEditDrawer();

    const cost = screen.getByDisplayValue('1800');
    expect(cost).toBeDisabled();
    expect(screen.getByText('Dari BOM')).toBeInTheDocument();
    expect(screen.getByText(/mengikuti bom yang sudah selesai/i)).toBeInTheDocument();
  });

  it('keeps the cost price editable and shows the BOM cost beside it when the BOM is not complete', async () => {
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '1000.00', bom_complete: false, has_bom: true, bom_cost: '1800.00' }));
    await openEditDrawer();

    expect(screen.getByDisplayValue('1000')).not.toBeDisabled();
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
    await renderEditHost({ extraMenus: ['purchase_orders'] });
    await screen.findByText('Keychain A');
    await user.click(editButton());
    await screen.findByDisplayValue('Standard');

    await user.click(screen.getByRole('button', { name: /tambah varian/i }));
    await user.click(await screen.findByRole('combobox', { name: /bom untuk varian baru ini/i }));
    await user.click(await screen.findByRole('option', { name: /salin bom dari ART-KY-001-001/i }));
    await user.click(screen.getByText('Simpan item'));

    await waitFor(() => expect(addVariant).toHaveBeenCalled());
    expect(addVariant.mock.calls[0][1]).toMatchObject({ copy_bom_from_variant_id: 10 });
  });

  it('does not offer the BOM choice for a new variant when no saved variant has a BOM', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue(PRODUCT({ cost_price: '0.00', bom_complete: false, has_bom: false, bom_cost: null }));
    await renderEditHost({ extraMenus: ['purchase_orders'] });
    await screen.findByText('Keychain A');
    await user.click(editButton());
    await screen.findByDisplayValue('Standard');

    await user.click(screen.getByRole('button', { name: /tambah varian/i }));

    expect(screen.queryByText('BOM untuk varian baru ini')).not.toBeInTheDocument();
  });
});

// 043 (T035) — fakta item baru di drawer: nama pendek, kategori pajak, estimasi persiapan, penanda ritel.
describe('ProductsView — item facts in the form drawer (043)', () => {
  const FULL = (extra = {}) => ({
    id: 1, home_outlet_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001',
    description: '', is_preorder: false, preorder_eta: null, is_active: true, image_url: null,
    short_name: 'Kopi', tax_category: 'restaurant_food_beverage', prep_minutes: 3, is_retail: false,
    variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', cost_price: '1000.00', sell_price: '3000.00', current_stock: 5, low_stock_alert: null, is_active: true, image_url: null }],
    ...extra,
  });

  beforeEach(seedList);

  async function openEdit(full) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    getProduct.mockResolvedValue(full);
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
    await renderEditHost();
    await screen.findByText('Keychain A');
    await user.click(editButton());
    await screen.findByDisplayValue('Standard');
    return user;
  }

  it('fills the four facts from the product when editing', async () => {
    await openEdit(FULL());

    expect(screen.getByLabelText(/^nama pendek/i)).toHaveValue('Kopi');
    expect(screen.getByLabelText(/^estimasi persiapan/i)).toHaveValue(3);
    expect(screen.getByLabelText(/item ritel/i)).not.toBeChecked();
    // kategori pajak ditampilkan dengan labelnya, bukan kuncinya
    expect(screen.getByText('Makanan & minuman restoran')).toBeInTheDocument();
  });

  it('limits the short name to 24 characters', async () => {
    await openEdit(FULL());
    expect(screen.getByLabelText(/^nama pendek/i)).toHaveAttribute('maxlength', '24');
  });

  it('offers the three tax categories with their stored keys', async () => {
    const user = await openEdit(FULL());
    const select = screen.getByRole('combobox', { name: /kategori pajak/i });
    const options = within(select).getAllByRole('option');
    expect(options.map((o) => [o.value, o.textContent.trim()])).toEqual(
      expect.arrayContaining([['restaurant_food_beverage', 'Makanan & minuman restoran'], ['retail', 'Retail'], ['other', 'Lainnya']]),
    );
  });

  it('sends the facts with the product update, changed values included', async () => {
    const user = await openEdit(FULL());

    const prep = screen.getByLabelText(/^estimasi persiapan/i);
    await user.clear(prep);
    await user.type(prep, '7');
    await user.click(screen.getByLabelText(/item ritel/i));
    await user.selectOptions(screen.getByRole('combobox', { name: /kategori pajak/i }), 'retail');
    await user.click(screen.getByText('Simpan item'));

    await waitFor(() => expect(updateProduct).toHaveBeenCalled());
    expect(updateProduct.mock.calls[0][1]).toMatchObject({ short_name: 'Kopi', tax_category: 'retail', prep_minutes: 7, is_retail: true });
  });

  it('clears empty optional facts to null so an old value can be removed', async () => {
    const user = await openEdit(FULL());
    await user.clear(screen.getByLabelText(/^nama pendek/i));
    await user.clear(screen.getByLabelText(/^estimasi persiapan/i));
    await user.click(screen.getByText('Simpan item'));

    await waitFor(() => expect(updateProduct).toHaveBeenCalled());
    expect(updateProduct.mock.calls[0][1]).toMatchObject({ short_name: null, prep_minutes: null });
  });

  it('shows a server validation error on the short name field', async () => {
    const user = await openEdit(FULL());
    updateProduct.mockRejectedValueOnce(Object.assign(new Error('invalid'), { isValidation: true, errors: { short_name: ['Maksimal 24 karakter.'] } }));
    await user.click(screen.getByText('Simpan item'));
    expect(await screen.findByText('Maksimal 24 karakter.')).toBeInTheDocument();
  });

  it('a new item starts with empty facts and sends them on create', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createProduct.mockResolvedValue({ id: 5, variants: [{ id: 50 }] });
    await renderProducts();
    await screen.findByText('Keychain A');

    await user.click(screen.getAllByRole('button', { name: /tambah item/i })[0]);
    await screen.findByTestId('product-form');
    expect(screen.getByLabelText(/^nama pendek/i)).toHaveValue('');
    expect(screen.getByLabelText(/item ritel/i)).not.toBeChecked();

    await user.type(screen.getByLabelText(/^nama item/i), 'Roti bakar');
    await user.type(screen.getByLabelText(/^nama pendek/i), 'Roti');
    await user.selectOptions(screen.getByLabelText(/^kategori menu/i), 'Kategori A');
    await user.type(screen.getByLabelText(/^harga dasar/i), '15000');
    await user.click(screen.getByText('Simpan item'));

    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(createProduct.mock.calls[0][0]).toMatchObject({ name: 'Roti bakar', short_name: 'Roti', tax_category: null, prep_minutes: null, is_retail: false });
  });

  it('opens the form with "Item ritel" pre-checked from the retail tab', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderProductsPage({ menus: ['dashboard', 'products'], url: '/products?tab=retail&view=table' });
    await screen.findByText('Keychain A');

    await user.click(screen.getByRole('button', { name: /^item ritel$/i }));
    await screen.findByTestId('product-form');
    expect(screen.getByLabelText(/item ritel \(/i)).toBeChecked();
  });
});
