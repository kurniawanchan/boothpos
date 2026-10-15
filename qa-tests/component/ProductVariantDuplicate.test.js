import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listProducts, getProduct, addVariant, updateVariant, updateProduct } from '../../resources/js/api/products';
import { createAdjustment } from '../../resources/js/api/stock';
import { listArtists } from '../../resources/js/api/artists';
import { listCategories } from '../../resources/js/api/categories';
import id from '../../resources/js/locales/id.json';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), deleteProduct: vi.fn(),
  addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/stock', () => ({ createAdjustment: vi.fn(), listMovements: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listBomLines: vi.fn(), copyBomFrom: vi.fn(), copyBomOut: vi.fn(), saveBomQuantities: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

const M = id.master_data;
const PRODUCT = {
  id: 2, artist_id: 1, category_id: 1, name: 'MCYT', code_prefix: 'SPF-KC-MCY', description: null, is_preorder: false, is_active: true, image_url: null,
  variants: [
    { id: 10, sku: 'SPF-KC-MCY-001', variant_name: 'Red', cost_price: '1000.00', sell_price: '2500.00', current_stock: 6, low_stock_alert: 4, is_active: true, has_bom: true, bom_cost: '397.00', bom_complete: false, image_url: 'https://example.test/red.png' },
    { id: 11, sku: 'SPF-KC-MCY-002', variant_name: 'Blue', cost_price: '1000.00', sell_price: '2500.00', current_stock: 9, low_stock_alert: null, is_active: false, has_bom: false, bom_cost: null, bom_complete: false, image_url: null },
    { id: 12, sku: 'SPF-KC-MCY-003', variant_name: 'Green', cost_price: '397.00', sell_price: '1000.00', current_stock: 2, low_stock_alert: null, is_active: true, has_bom: true, bom_cost: '397.00', bom_complete: true, image_url: null },
  ],
};

async function openEdit(menuKeys = ['dashboard', 'products', 'purchase_orders']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  render(ProductsView, { global: { plugins: [pinia], stubs: { 'router-link': true } } });
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  await screen.findByText('MCYT');
  await user.click(screen.getAllByRole('button', { name: id.common.edit })[0]);
  await screen.findAllByTestId('variant-card');

  return user;
}

const cards = () => screen.getAllByTestId('variant-card');
const dup = (user, index) => user.click(within(cards()[index]).getByRole('button', { name: M.duplicate_variant }));
const value = (card, label) => within(card).getByLabelText(new RegExp(`^${label}`)).value;

// 037-variant-drawer-bom-ui (US2) — Duplikat varian: kartu belum-tersimpan, dibenihi dari sumbernya.
describe('Duplicate variant in the Edit product drawer (037)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [{ id: 2, code_prefix: 'SPF-KC-MCY', name: 'MCYT', artist_name: 'A', category_name: 'K', is_preorder: false, is_active: true, image_url: null, variants: [] }], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    getProduct.mockResolvedValue(PRODUCT);
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    updateProduct.mockResolvedValue({});
    updateVariant.mockImplementation(async (variantId) => ({ id: variantId }));
    addVariant.mockResolvedValue({ id: 99 });
    createAdjustment.mockResolvedValue({});
  });

  it('inserts ONE unsaved card directly below the source, seeded from it, leaving the source unchanged', async () => {
    const user = await openEdit();

    await dup(user, 0);

    expect(cards()).toHaveLength(4);
    const copy = cards()[1];
    expect(value(copy, M.variant_name)).toBe(M.variant_copy_suffix.replace('{name}', 'Red'));
    expect(value(copy, M.cost_price)).toBe('1000.00');
    expect(value(copy, M.sell_price)).toBe('2500.00');
    expect(value(copy, M.col_stock)).toBe('6');
    expect(within(copy).queryByText('SPF-KC-MCY-001')).not.toBeInTheDocument(); // belum punya SKU
    expect(copy.querySelector('img')).toBeNull(); // gambar tidak disalin
    expect(value(cards()[0], M.variant_name)).toBe('Red');
    expect(within(cards()[0]).getByText('SPF-KC-MCY-001')).toBeInTheDocument();
  });

  it('says the BOM will be copied and that the copied stock counts as new inventory, and asks for the adjustment reason', async () => {
    const user = await openEdit();

    await dup(user, 0);

    const copy = cards()[1];
    expect(within(copy).getByText(M.duplicate_bom_note.replace('{sku}', 'SPF-KC-MCY-001'))).toBeInTheDocument();
    expect(within(copy).getByText(M.duplicate_stock_note.replace('{qty}', '6'))).toBeInTheDocument();
    expect(screen.getByLabelText(new RegExp(M.stock_adjustment_reason))).toBeInTheDocument();
  });

  it('saves the copy through the existing new-variant flow with copy_bom_from_variant_id and records its stock', async () => {
    const user = await openEdit();
    await dup(user, 0);
    await user.type(screen.getByLabelText(new RegExp(M.stock_adjustment_reason)), 'duplikat varian');

    await user.click(screen.getByRole('button', { name: M.save_product }));

    await waitFor(() => expect(addVariant).toHaveBeenCalledTimes(1));
    expect(addVariant).toHaveBeenCalledWith(2, expect.objectContaining({
      variant_name: M.variant_copy_suffix.replace('{name}', 'Red'), cost_price: '1000.00', sell_price: '2500.00', low_stock_alert: 4, copy_bom_from_variant_id: 10,
    }));
    await waitFor(() => expect(createAdjustment).toHaveBeenCalledWith({ reason: 'duplikat varian', items: [{ variant_id: 99, qty_change: 6 }] }));
  });

  it('does not promise or send a BOM copy for a user without the purchase-orders permission, but still copies the fields', async () => {
    const user = await openEdit(['dashboard', 'products']);
    await dup(user, 0);

    const copy = cards()[1];
    expect(within(copy).queryByText(M.duplicate_bom_note.replace('{sku}', 'SPF-KC-MCY-001'))).not.toBeInTheDocument();
    expect(value(copy, M.sell_price)).toBe('2500.00');
    await user.type(screen.getByLabelText(new RegExp(M.stock_adjustment_reason)), 'x');
    await user.click(screen.getByRole('button', { name: M.save_product }));

    await waitFor(() => expect(addVariant).toHaveBeenCalled());
    expect(addVariant.mock.calls[0][1]).not.toHaveProperty('copy_bom_from_variant_id');
  });

  it('copies no BOM from a source that has none, and copies only fields from an unsaved source', async () => {
    const user = await openEdit();

    await dup(user, 1); // Blue: tanpa BOM
    expect(within(cards()[2]).queryByText(/BOM dari/)).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: M.add_variant }));
    const unsavedIndex = cards().length - 1;
    await user.clear(within(cards()[unsavedIndex]).getByLabelText(M.variant_name));
    await user.type(within(cards()[unsavedIndex]).getByLabelText(M.variant_name), 'Baru');
    await dup(user, unsavedIndex);

    expect(value(cards()[unsavedIndex + 1], M.variant_name)).toBe(M.variant_copy_suffix.replace('{name}', 'Baru'));
    expect(within(cards()[unsavedIndex + 1]).queryByText(/BOM dari/)).not.toBeInTheDocument();
  });

  it('gives an inactive source an ACTIVE copy, and a complete-BOM source an unlocked copy that keeps the cost value', async () => {
    const user = await openEdit();

    await dup(user, 1); // Blue nonaktif
    expect(cards()[2]).not.toHaveClass('opacity-50');
    expect(cards()[1]).toHaveClass('opacity-50'); // sumbernya tetap redup

    await dup(user, 3); // Green: BOM selesai (kini di indeks 3)
    const copy = cards()[4];
    expect(value(copy, M.cost_price)).toBe('397.00');
    expect(within(copy).getByLabelText(new RegExp(`^${M.cost_price}`))).not.toBeDisabled();
    expect(within(copy).queryByText(M.bom_from_bom)).not.toBeInTheDocument();
  });

  it('lets the user zero the copied stock, in which case no stock adjustment is queued', async () => {
    const user = await openEdit();
    await dup(user, 0);
    const stock = within(cards()[1]).getByLabelText(M.col_stock);
    await user.clear(stock);
    await user.type(stock, '0');

    await user.click(screen.getByRole('button', { name: M.save_product }));

    await waitFor(() => expect(addVariant).toHaveBeenCalled());
    expect(createAdjustment).not.toHaveBeenCalled();
  });

  it('discards an unsaved duplicate when the drawer is cancelled, and a duplicate of a duplicate works', async () => {
    const user = await openEdit();
    await dup(user, 0);
    await dup(user, 1);
    expect(cards()).toHaveLength(5);

    await user.click(screen.getByRole('button', { name: id.common.cancel }));
    await user.click(screen.getAllByRole('button', { name: id.common.edit })[0]);
    await screen.findAllByTestId('variant-card');

    expect(cards()).toHaveLength(3);
    expect(addVariant).not.toHaveBeenCalled();
  });
});
