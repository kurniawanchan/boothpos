import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listProducts, getProduct } from '../../resources/js/api/products';
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
    { id: 10, sku: 'SPF-KC-MCY-001', variant_name: 'Red', cost_price: '1000.00', sell_price: '2500.00', current_stock: 5, low_stock_alert: null, is_active: true, has_bom: true, bom_cost: '397.00', bom_complete: false, image_url: null },
    { id: 11, sku: 'SPF-KC-MCY-002', variant_name: 'Blue', cost_price: '1000.00', sell_price: '800.00', current_stock: 9, low_stock_alert: null, is_active: true, has_bom: false, bom_cost: null, bom_complete: false, image_url: 'https://example.test/blue.png' },
    { id: 12, sku: 'SPF-KC-MCY-003', variant_name: 'Green', cost_price: '397.00', sell_price: '1000.00', current_stock: 2, low_stock_alert: null, is_active: true, has_bom: true, bom_cost: '397.00', bom_complete: true, image_url: null },
  ],
};

async function openEdit() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'products', 'purchase_orders'] };
  render(ProductsView, { global: { plugins: [pinia], stubs: { 'router-link': true } } });
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  await screen.findByText('MCYT');
  await user.click(screen.getAllByRole('button', { name: id.common.edit })[0]);
  await screen.findAllByTestId('variant-card');

  return user;
}

const cards = () => screen.getAllByTestId('variant-card');
const header = (card) => within(card).getByTestId('variant-header');
const before = (a, b) => Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);

// 037-variant-drawer-bom-ui (US1) — kartu varian yang jelas, aksi di header, urutan kolom, thumbnail lebih besar.
describe('Variant card in the Edit product drawer (037)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [{ id: 2, code_prefix: 'SPF-KC-MCY', name: 'MCYT', artist_name: 'A', category_name: 'K', is_preorder: false, is_active: true, image_url: null, variants: [] }], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    getProduct.mockResolvedValue(PRODUCT);
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
  });

  it('shows each variant in its own bounded card inside a tray, in a wider drawer', async () => {
    await openEdit();

    expect(cards()).toHaveLength(3);
    for (const card of cards()) {
      expect(card).toHaveClass('bg-white', 'border', 'rounded-card', 'shadow-sm');
    }
    expect(screen.getByRole('dialog')).toHaveClass('max-w-[1040px]');
  });

  it('shows four chips in four different colours, in the order SKU, markup, margin, BOM cost', async () => {
    await openEdit();
    const h = header(cards()[0]);

    const sku = within(h).getByText('SPF-KC-MCY-001');
    const markup = within(h).getByText(M.markup_value.replace('{value}', '150'));
    const margin = within(h).getByText(M.margin.replace('{value}', '60'));
    const bom = within(h).getByText(M.bom_cost_label.replace('{cost}', 'Rp 397'));

    expect(sku).toHaveClass('bg-sky-bg', 'text-sky-text');
    expect(markup).toHaveClass('bg-mint-100', 'text-brand-active');
    expect(margin).toHaveClass('bg-violet-bg', 'text-violet-text');
    expect(bom).toHaveClass('bg-warn-bg', 'text-warn-text');
    expect(before(sku, markup) && before(markup, margin) && before(margin, bom)).toBe(true);
  });

  it('keeps the warning style for a negative markup/margin and shows no BOM cost chip without a BOM', async () => {
    await openEdit();
    const h = header(cards()[1]);

    expect(within(h).getByText(M.markup_value.replace('{value}', '-20'))).toHaveClass('bg-danger-bg', 'text-danger-text');
    expect(within(h).getByText(M.margin.replace('{value}', '-25'))).toHaveClass('bg-danger-bg', 'text-danger-text');
    expect(within(h).queryByText(/Rp 397/)).not.toBeInTheDocument();
  });

  it('puts Open BOM, Apply markup and Delete on the header right, in that order', async () => {
    await openEdit();
    const h = header(cards()[0]);

    const open = within(h).getByRole('button', { name: M.bom_open });
    const apply = within(h).getByRole('button', { name: M.apply_markup });
    const del = within(h).getByRole('button', { name: M.delete_variant.replace('{name}', 'Red') });

    expect(before(open, apply) && before(apply, del)).toBe(true);
    // chips come first (left), actions after
    expect(before(within(h).getByText('SPF-KC-MCY-001'), open)).toBe(true);
  });

  it('explains what a BOM is in a tooltip on the Open BOM button (hover and keyboard focus)', async () => {
    const user = await openEdit();
    const open = within(header(cards()[0])).getByRole('button', { name: M.bom_open });

    await user.hover(open);
    expect(screen.getByRole('tooltip')).toHaveTextContent(M.bom_open_tip);
    await user.unhover(open);

    open.focus();
    expect(await screen.findByRole('tooltip')).toBeVisible();
  });

  it('offers neither Open BOM nor a BOM cost chip on a brand-new unsaved variant, but keeps Apply markup and Delete', async () => {
    const user = await openEdit();

    await user.click(screen.getByRole('button', { name: M.add_variant }));
    const fresh = cards()[3];
    const h = header(fresh);

    expect(within(h).queryByRole('button', { name: M.bom_open })).not.toBeInTheDocument();
    expect(within(h).queryByText(/BOM/)).not.toBeInTheDocument();
    expect(within(h).getByRole('button', { name: M.apply_markup })).toBeInTheDocument();
    expect(within(h).getByRole('button', { name: /Hapus varian|Delete variant/i })).toBeInTheDocument();
  });

  it('orders the fields variant name, stock, cost price, sell price', async () => {
    await openEdit();
    const card = cards()[0];

    const name = within(card).getByLabelText(M.variant_name);
    const stock = within(card).getByLabelText(M.col_stock);
    const cost = within(card).getByLabelText(M.cost_price);
    const sell = within(card).getByLabelText(M.sell_price);

    expect(before(name, stock) && before(stock, cost) && before(cost, sell)).toBe(true);
  });

  it('shows a 66px picture, and a same-size placeholder when the variant has none', async () => {
    await openEdit();

    const img = within(cards()[1]).getByAltText(id.master_data.current_variant_image);
    expect(img).toHaveClass('h-[66px]', 'w-[66px]');
    const placeholder = cards()[0].querySelector('.ph-image').parentElement;
    expect(placeholder).toHaveClass('h-[66px]', 'w-[66px]');
  });

  it('still applies the markup to the sell price and still locks the cost price of a completed BOM', async () => {
    const user = await openEdit();
    const first = cards()[0];

    await user.clear(within(first).getByLabelText(M.sell_price));
    await user.click(within(header(first)).getByRole('button', { name: M.apply_markup }));

    await waitFor(() => expect(within(first).getByLabelText(M.sell_price)).toHaveValue(2500)); // 1000 x (1 + 150%)
    // label berisi teks petunjuk kunci di dalamnya, jadi dicocokkan dengan awalan
    expect(within(cards()[2]).getByLabelText(new RegExp(`^${M.cost_price}`))).toBeDisabled();
  });
});
