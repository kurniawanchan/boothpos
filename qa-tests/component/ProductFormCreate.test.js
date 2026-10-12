import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within, fireEvent } from '@testing-library/vue';
import { renderDrawerHost } from './productFormDrawerHost';
import { useToastStore } from '../../resources/js/stores/toast';
import { getProduct, createProduct, updateProduct, updateVariant, saveVariants, saveModifierLinks, uploadProductImage, uploadVariantImage } from '../../resources/js/api/products';
import { createAdjustment } from '../../resources/js/api/stock';
import { listStations } from '../../resources/js/api/stations';
import { listModifierGroups } from '../../resources/js/api/modifierGroups';
import id from '../../resources/js/locales/id.json';

vi.mock('../../resources/js/api/products', () => ({
  getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), addVariant: vi.fn(), updateVariant: vi.fn(),
  uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(), saveVariants: vi.fn(), saveModifierLinks: vi.fn(),
}));
vi.mock('../../resources/js/api/stock', () => ({ createAdjustment: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn() }));

const P = id.products;
const GROUPS = [
  { id: 7, name: 'Gula', selection_type: 'multiple', is_required: false, min_select: 0, max_select: 3, is_active: true },
  { id: 8, name: 'Ukuran', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, is_active: true },
];

async function openCreate(opts = {}) {
  const host = renderDrawerHost(opts);
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: /^tambah item$/i }));
  await screen.findByTestId('product-form');
  return { user, ...host };
}

// Isian minimum yang sah: nama, kategori, harga dasar.
async function fillMinimum(user) {
  await user.type(screen.getByLabelText(/^nama item/i), 'Kopi Susu Pandan');
  await user.selectOptions(screen.getByLabelText(/^kategori menu/i), 'Kategori A');
  await user.type(screen.getByLabelText(/^harga dasar/i), '18000');
}

const save = (user) => user.click(screen.getByRole('button', { name: P.f_save_item }));
const toasts = () => useToastStore().items.map((i) => i.message);

describe('ProductFormDrawer — create mode follows the Menu item design (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [{ id: 3, name: 'Bar' }] });
    listModifierGroups.mockResolvedValue({ data: GROUPS });
    createProduct.mockResolvedValue({ id: 55, variants: [{ id: 501, variant_name: 'Standard' }] });
    saveVariants.mockResolvedValue({ applied: [], pending: [] });
    saveModifierLinks.mockResolvedValue({ applied: true });
    createAdjustment.mockResolvedValue({});
  });

  it('has the design header, flat sections in order, and the Batal / Simpan item footer', async () => {
    await openCreate();

    expect(screen.getByText(P.f_kicker_new)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: P.f_menu_item })).toBeInTheDocument();
    const headings = within(screen.getByTestId('product-form')).getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
    expect(headings).toEqual([P.f_sec_basic, P.f_sec_price, P.f_sec_variants, P.f_sec_modifier, P.f_sec_kitchen, P.f_sec_availability, P.f_sec_other]);
    expect(screen.getByRole('button', { name: id.common.cancel })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: P.f_save_item })).toBeInTheDocument();
    expect(screen.getByLabelText(/^nama item/i)).toHaveAttribute('placeholder', P.f_item_name_ph);
    expect(screen.getByLabelText(/^nama pendek/i)).toHaveAttribute('maxlength', '24');
  });

  it('replaces the dark code preview card with a one-line note', async () => {
    await openCreate();

    expect(screen.getByTestId('code-note')).toHaveTextContent(P.f_code_note);
    expect(screen.queryByText(/auto-generated product code|preview/i)).not.toBeInTheDocument();
    expect(document.querySelector('.bg-ink.font-mono')).toBeNull();
  });

  it('omits every control that has no backend: open price, outlets, channels, daily quota, outlet price columns', async () => {
    await openCreate();
    const form = screen.getByTestId('product-form');

    for (const text of [/harga terbuka/i, /aktif di outlet/i, /wjm/i, /channel/i, /dine-in|takeaway|gofood|grabfood|qr self/i, /kuota harian/i, /porsi/i, /per outlet|harga outlet|outlet price/i]) {
      expect(within(form).queryByText(text)).not.toBeInTheDocument();
    }
    // tabel varian: hanya kolom yang punya backend
    const headers = within(screen.getByTestId('variants-table')).getAllByRole('columnheader').map((h) => h.textContent.trim()).filter(Boolean);
    expect(headers).toEqual([P.f_col_variant, P.f_col_sku, P.f_col_image, P.f_col_price, P.f_col_stock, P.f_col_active]);
  });

  it('keeps what the API needs: outlet, segment, retail and pre-order checkboxes, product image', async () => {
    const { user } = await openCreate();

    expect(screen.getByLabelText(new RegExp(`^${id.master_data.owner_artist}`))).toHaveValue('1'); // satu-satunya outlet dipilih
    expect(screen.getByLabelText(id.master_data.segment_3)).toBeEnabled();
    expect(screen.getByLabelText(/item ritel/i)).toBeInTheDocument();
    expect(screen.getByLabelText(id.master_data.product_image)).toBeInTheDocument();
    await user.click(screen.getByLabelText(id.master_data.preorder_product));
    expect(screen.getByLabelText(id.master_data.eta)).toBeInTheDocument();
  });

  it('sends name, short name, category, outlet, tax, prep minutes, station and the first-variant price', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await user.type(screen.getByLabelText(/^nama pendek/i), 'Kopsu Pandan');
    await user.selectOptions(screen.getByLabelText(/^kategori pajak/i), 'restaurant_food_beverage');
    await user.type(screen.getByLabelText(/^estimasi persiapan/i), '6');
    await user.selectOptions(screen.getByLabelText(/^station dapur/i), 'Bar');
    await user.type(screen.getByLabelText(id.master_data.description), 'Dingin');
    await save(user);

    await waitFor(() => expect(createProduct).toHaveBeenCalledTimes(1));
    expect(createProduct.mock.calls[0][0]).toMatchObject({
      name: 'Kopi Susu Pandan', short_name: 'Kopsu Pandan', category_id: 1, home_outlet_id: 1,
      tax_category: 'restaurant_food_beverage', prep_minutes: 6, station_id: 3, description: 'Dingin', is_retail: false, is_active: true,
    });
    expect(createProduct.mock.calls[0][0].variants).toEqual([
      { variant_name: 'Standard', cost_price: '0.00', sell_price: '18000.00', low_stock_alert: null, track_stock: true },
    ]);
  });

  it('keeps the item base price and the first variant price in sync both ways', async () => {
    const { user } = await openCreate();
    const base = screen.getByLabelText(/^harga dasar/i);
    const firstRow = () => screen.getByLabelText(P.f_variant_price_aria.replace('{n}', '1'));

    await user.type(base, '12000');
    expect(firstRow()).toHaveValue(12000);

    await user.clear(firstRow());
    await user.type(firstRow(), '15500');
    expect(base).toHaveValue(15500);

    // varian kedua tidak ikut menggeser harga dasar item
    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    await user.type(screen.getByLabelText(P.f_variant_price_aria.replace('{n}', '2')), '20000');
    expect(base).toHaveValue(15500);
  });

  it('sends several variants, with a name for each, and the status as is_active', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await user.type(screen.getByLabelText(P.f_variant_name_aria.replace('{n}', '1')), 'Panas');
    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    await user.type(screen.getByLabelText(P.f_variant_name_aria.replace('{n}', '2')), 'Es');
    await user.type(screen.getByLabelText(P.f_variant_price_aria.replace('{n}', '2')), '20000');
    await user.click(screen.getByRole('button', { name: P.f_status_draft }));
    createProduct.mockResolvedValue({ id: 55, variants: [{ id: 501 }, { id: 502 }] });
    await save(user);

    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    const payload = createProduct.mock.calls[0][0];
    expect(payload.is_active).toBe(false);
    expect(payload.variants.map((v) => [v.variant_name, v.sell_price])).toEqual([['Panas', '18000.00'], ['Es', '20000.00']]);
  });

  it('defaults the status to Aktif', async () => {
    const { user } = await openCreate();
    expect(screen.getByRole('button', { name: P.f_status_active })).toHaveAttribute('aria-pressed', 'true');
    await fillMinimum(user);
    await save(user);
    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(createProduct.mock.calls[0][0].is_active).toBe(true);
  });

  it('shows the status as read-only text, without the toggle, while menu release is on', async () => {
    await openCreate({ release: true });

    expect(screen.getByTestId('status-release-note')).toHaveTextContent(P.f_status_release);
    expect(screen.queryByTestId('status-toggle')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: P.f_status_draft })).not.toBeInTheDocument();
    expect(screen.getByText(P.f_sec_price_desc_release, { exact: false })).toBeInTheDocument();
  });

  it('marks unchecked variants inactive with ONE follow-up call after the item is created', async () => {
    const { user, saved } = await openCreate();
    await fillMinimum(user);
    await user.type(screen.getByLabelText(P.f_variant_name_aria.replace('{n}', '1')), 'Biasa');
    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    await user.type(screen.getByLabelText(P.f_variant_name_aria.replace('{n}', '2')), 'Jumbo');
    await user.type(screen.getByLabelText(P.f_variant_price_aria.replace('{n}', '2')), '25000');
    await user.click(screen.getByLabelText(P.f_variant_active_aria.replace('{n}', '2')));
    createProduct.mockResolvedValue({ id: 55, variants: [{ id: 501 }, { id: 502 }] });
    await save(user);

    await waitFor(() => expect(saveVariants).toHaveBeenCalledTimes(1));
    expect(saveVariants).toHaveBeenCalledWith(55, { variants: [{ id: 502, variant_name: 'Jumbo', sell_price: '25000.00', is_active: false }] });
    expect(createProduct.mock.calls[0][0].variants[1]).not.toHaveProperty('is_active');
    expect(saved).toHaveBeenCalledWith({ id: 55, created: true });
  });

  it('does not call the variants endpoint when every variant is active', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await save(user);
    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(saveVariants).not.toHaveBeenCalled();
  });

  it('shows an error but still closes and reports the created item when the inactive follow-up fails', async () => {
    saveVariants.mockRejectedValue(new Error('boom'));
    const { user, saved } = await openCreate();
    await fillMinimum(user);
    await user.click(screen.getByLabelText(P.f_variant_active_aria.replace('{n}', '1')));
    await save(user);

    await waitFor(() => expect(saved).toHaveBeenCalledWith({ id: 55, created: true }));
    expect(toasts()).toContain(P.f_inactive_failed);
    expect(createProduct).toHaveBeenCalledTimes(1); // tidak pernah mengulang POST (akan menggandakan item)
    expect(screen.queryByTestId('product-form')).not.toBeInTheDocument();
  });

  it('links modifier groups after create with required/min/max/order, max empty = unlimited', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    const group = (n) => screen.getByLabelText(P.f_modifier_group_aria.replace('{n}', n));

    await user.selectOptions(group(1), 'Gula');
    await user.click(screen.getByRole('button', { name: P.f_link_group }));
    await user.selectOptions(group(2), 'Ukuran');
    // baris 1: kosongkan maksimum (tanpa batas) dan tandai wajib
    await user.clear(screen.getByLabelText(P.f_modifier_max_aria.replace('{n}', '1')));
    await user.click(screen.getByLabelText(P.f_modifier_required_aria.replace('{n}', '1')));
    await save(user);

    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    expect(saveModifierLinks).toHaveBeenCalledWith(55, [
      { group_id: 7, is_required: true, min_select: 1, max_select: null, display_order: 1 },
      { group_id: 8, is_required: true, min_select: 1, max_select: 1, display_order: 2 },
    ]);
  });

  it('offers a group in only one row, and skips the blank opening row (no call without a chosen group)', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await user.selectOptions(screen.getByLabelText(P.f_modifier_group_aria.replace('{n}', '1')), 'Gula');
    await user.click(screen.getByRole('button', { name: P.f_link_group }));
    const second = screen.getByLabelText(P.f_modifier_group_aria.replace('{n}', '2'));
    expect(within(second).queryByRole('option', { name: 'Gula' })).not.toBeInTheDocument();
    expect(within(second).getByRole('option', { name: 'Ukuran' })).toBeInTheDocument();
  });

  it('never calls the modifier endpoint when no group was chosen', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await save(user);
    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(saveModifierLinks).not.toHaveBeenCalled();
  });

  it('shows an error but still reports the created item when the modifier follow-up fails', async () => {
    saveModifierLinks.mockRejectedValue(new Error('boom'));
    const { user, saved } = await openCreate();
    await fillMinimum(user);
    await user.selectOptions(screen.getByLabelText(P.f_modifier_group_aria.replace('{n}', '1')), 'Gula');
    await save(user);

    await waitFor(() => expect(saved).toHaveBeenCalledWith({ id: 55, created: true }));
    expect(toasts()).toContain(P.f_links_failed);
  });

  it('shows the Modifier section with an empty-state hint (no table) when there are no groups yet', async () => {
    listModifierGroups.mockResolvedValue({ data: [] });
    await openCreate();
    expect(await screen.findByTestId('create-modifiers-empty')).toHaveTextContent(P.f_modifier_none);
    expect(screen.queryByTestId('create-modifier-row')).not.toBeInTheDocument();
  });

  it('hides the Modifier section when the group list cannot be read (no permission)', async () => {
    listModifierGroups.mockRejectedValue(Object.assign(new Error('forbidden'), { status: 403 }));
    await openCreate();
    expect(screen.queryByTestId('create-modifiers')).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: P.f_sec_modifier })).not.toBeInTheDocument();
  });

  it('validates modifier rules before creating anything (min above max)', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await user.selectOptions(screen.getByLabelText(P.f_modifier_group_aria.replace('{n}', '1')), 'Gula');
    await user.clear(screen.getByLabelText(P.f_modifier_min_aria.replace('{n}', '1')));
    await user.type(screen.getByLabelText(P.f_modifier_min_aria.replace('{n}', '1')), '5');
    await save(user);

    expect(await screen.findByText(id.products.ml_err_max)).toBeInTheDocument();
    expect(createProduct).not.toHaveBeenCalled();
  });

  it('blocks a save with no name, no category or no price, and shows each error at its field', async () => {
    const { user } = await openCreate();
    await save(user);

    expect(await screen.findAllByText(P.f_required)).toHaveLength(2); // nama + kategori
    expect(screen.getByText(P.f_price_invalid)).toBeInTheDocument();
    expect(createProduct).not.toHaveBeenCalled();
  });

  it('requires a name for each variant once there is more than one', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    await user.type(screen.getByLabelText(P.f_variant_price_aria.replace('{n}', '2')), '1000');
    await save(user);

    expect(await screen.findAllByText(P.f_variant_name_required)).not.toHaveLength(0);
    expect(createProduct).not.toHaveBeenCalled();
  });

  it('shows a server 422 on the field it belongs to and keeps the form open', async () => {
    createProduct.mockRejectedValue(Object.assign(new Error('invalid'), { isValidation: true, errors: { short_name: ['Terlalu panjang.'] } }));
    const { user } = await openCreate();
    await fillMinimum(user);
    await save(user);

    expect(await screen.findByText('Terlalu panjang.')).toBeInTheDocument();
    expect(screen.getByTestId('product-form')).toBeInTheDocument();
  });

  it('records the opening stock through a stock adjustment with a fixed reason, only for rows that have stock', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await user.type(screen.getByLabelText(P.f_variant_stock_aria.replace('{n}', '1')), '12');
    await save(user);

    await waitFor(() => expect(createAdjustment).toHaveBeenCalledTimes(1));
    expect(createAdjustment).toHaveBeenCalledWith({ reason: P.f_initial_stock_reason, items: [{ variant_id: 501, qty_change: 12 }] });
  });

  it('uploads the chosen product image to the created item', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    const file = new File(['x'], 'kopi.png', { type: 'image/png' });
    await user.upload(screen.getByLabelText(id.master_data.product_image), file);
    await save(user);

    await waitFor(() => expect(uploadProductImage).toHaveBeenCalledWith(55, file));
  });
});


// 043 — gambar per varian di formulir BUAT: kolom GAMBAR, pratinjau lokal, unggah SESUDAH item dibuat (id varian
// dipetakan menurut urutan baris), galat validasi, dan kegagalan unggah tidak pernah mengulang POST.
describe('ProductFormDrawer — variant image column in create mode (043)', () => {
  const realCreate = URL.createObjectURL;
  const realRevoke = URL.revokeObjectURL;
  const png = (name) => new File(['x'], name, { type: 'image/png' });
  const imageInput = (n) => screen.getByLabelText(P.f_variant_image_aria.replace('{n}', String(n)));

  beforeEach(() => {
    vi.clearAllMocks();
    URL.createObjectURL = vi.fn(() => 'blob:create-1');
    URL.revokeObjectURL = vi.fn();
    listStations.mockResolvedValue({ data: [] });
    listModifierGroups.mockResolvedValue({ data: GROUPS });
    createProduct.mockResolvedValue({ id: 55, variants: [{ id: 501 }, { id: 502 }] });
    saveVariants.mockResolvedValue({ applied: [], pending: [] });
    saveModifierLinks.mockResolvedValue({ applied: true });
    uploadVariantImage.mockResolvedValue({});
  });
  afterEach(() => {
    URL.createObjectURL = realCreate;
    URL.revokeObjectURL = realRevoke;
  });

  async function twoVariants(user) {
    await fillMinimum(user);
    await user.type(screen.getByLabelText(P.f_variant_name_aria.replace('{n}', '1')), 'Panas');
    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    await user.type(screen.getByLabelText(P.f_variant_name_aria.replace('{n}', '2')), 'Es');
    await user.type(screen.getByLabelText(P.f_variant_price_aria.replace('{n}', '2')), '20000');
  }

  it('shows a placeholder thumbnail per row, previews the chosen file locally, and lets the user discard it', async () => {
    const { user } = await openCreate();
    const row = () => within(screen.getAllByTestId('variant-row')[0]);
    expect(document.querySelector('[data-testid="variant-row"] .ph-image')).not.toBeNull();

    await user.upload(imageInput(1), png('panas.png'));
    expect(row().getByAltText(P.f_variant_image_alt.replace('{n}', '1'))).toHaveAttribute('src', 'blob:create-1');

    await user.click(row().getByRole('button', { name: P.f_variant_image_clear_aria.replace('{n}', '1') }));
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:create-1');
    expect(row().queryByAltText(P.f_variant_image_alt.replace('{n}', '1'))).not.toBeInTheDocument();
  });

  it('uploads each chosen image to the variant created at the same position, after POST /products', async () => {
    const { user } = await openCreate();
    await twoVariants(user);
    const first = png('panas.png');
    const second = png('es.png');
    await user.upload(imageInput(1), first);
    await user.upload(imageInput(2), second);
    await save(user);

    await waitFor(() => expect(uploadVariantImage).toHaveBeenCalledTimes(2));
    expect(uploadVariantImage).toHaveBeenCalledWith(501, first);
    expect(uploadVariantImage).toHaveBeenCalledWith(502, second);
    // gambar TIDAK ikut dikirim dalam badan POST (server tidak menerimanya di sana)
    expect(JSON.stringify(createProduct.mock.calls[0][0])).not.toMatch(/image/);
  });

  it('uploads only for the row that has a file (the second variant here)', async () => {
    const { user } = await openCreate();
    await twoVariants(user);
    const file = png('es.png');
    await user.upload(imageInput(2), file);
    await save(user);

    await waitFor(() => expect(uploadVariantImage).toHaveBeenCalledTimes(1));
    expect(uploadVariantImage).toHaveBeenCalledWith(502, file);
  });

  it('shows the validation error under the thumbnail, keeps nothing for that row and uploads nothing', async () => {
    const { user } = await openCreate();
    await fillMinimum(user);
    await fireEvent.change(imageInput(1), { target: { files: [new File(['x'], 'catatan.txt', { type: 'text/plain' })] } });

    expect(within(screen.getAllByTestId('variant-row')[0]).getByRole('alert')).toHaveTextContent(id.master_data.image_must_be_image_generic);
    await save(user);
    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(uploadVariantImage).not.toHaveBeenCalled();
  });

  it('toasts an upload failure but still reports the created item and never repeats the POST', async () => {
    uploadVariantImage.mockRejectedValue(new Error('boom'));
    const { user, saved } = await openCreate();
    await fillMinimum(user);
    createProduct.mockResolvedValue({ id: 55, variants: [{ id: 501 }] });
    await user.upload(imageInput(1), png('panas.png'));
    await save(user);

    await waitFor(() => expect(saved).toHaveBeenCalledWith({ id: 55, created: true }));
    expect(toasts()).toContain(id.master_data.product_saved_image_failed);
    expect(createProduct).toHaveBeenCalledTimes(1);
  });
});

// Mode UBAH = formulir yang SAMA dengan mode buat (bagian, urutan, tabel, kepala/kaki, lebar), tanpa Status dan
// dengan segmen terkunci. Tabel varian dan aksi barisnya diuji di ProductVariantsTable.test.js.
describe('ProductFormDrawer — edit mode is the same form as create mode (043)', () => {
  const LINKS = [
    { group_id: 7, name: 'Gula', source: 'product', has_rules: true, is_required: false, min_select: 0, max_select: 3, display_order: 1 },
    { group_id: 8, name: 'Ukuran', source: 'category', has_rules: false, is_required: true, min_select: 1, max_select: 1, display_order: 2 },
  ];
  const FULL = (extra = {}) => ({
    id: 1, home_outlet_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001', product_segment: 'KEY', description: '',
    is_preorder: false, preorder_eta: null, is_active: true, image_url: null, short_name: null, tax_category: null, prep_minutes: null, is_retail: false,
    variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', cost_price: '1000.00', sell_price: '3000.00', current_stock: 5, low_stock_alert: null, is_active: true, track_stock: true, image_url: null }],
    modifier_links: LINKS,
    ...extra,
  });

  beforeEach(() => {
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [] });
    listModifierGroups.mockResolvedValue({ data: GROUPS });
    getProduct.mockResolvedValue(FULL());
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
    saveModifierLinks.mockResolvedValue({ applied: true });
  });

  async function openEdit(opts = {}) {
    const host = renderDrawerHost(opts);
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /^ubah keychain a$/i }));
    await screen.findAllByTestId('variant-row');
    return { user, ...host };
  }

  it('shows "Ubah" over the item name and the same sections in the same order as create, minus Status', async () => {
    await openEdit();
    await screen.findByRole('heading', { name: P.f_sec_modifier });

    expect(screen.getByText(P.f_kicker_edit)).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Keychain A' })).toBeInTheDocument();
    const headings = within(screen.getByTestId('product-form')).getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
    expect(headings).toEqual([P.f_sec_basic, P.f_sec_price, P.f_sec_variants, P.f_sec_modifier, P.f_sec_kitchen, P.f_sec_other]);
    expect(screen.queryByRole('heading', { name: P.f_sec_availability })).not.toBeInTheDocument();
    expect(screen.queryByTestId('status-toggle')).not.toBeInTheDocument();
    expect(screen.queryByTestId('code-note')).not.toBeInTheDocument();
  });

  it('uses the same drawer width, footer and form controls as create mode', async () => {
    await openEdit();

    expect(screen.getByRole('dialog')).toHaveClass('max-w-[1000px]');
    expect(screen.getByRole('button', { name: id.common.cancel })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: P.f_save_item })).toBeInTheDocument();
    expect(screen.getByLabelText(/^nama item/i)).toHaveValue('Keychain A');
    expect(screen.getByLabelText(/^kategori pajak/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/^estimasi persiapan/i)).toBeInTheDocument();
    expect(screen.getByLabelText(id.master_data.product_image)).toBeInTheDocument();
    expect(screen.getByLabelText(id.master_data.preorder_product)).toBeInTheDocument();
    expect(screen.getByLabelText(id.master_data.segment_3)).toBeDisabled(); // server tidak pernah menerimanya saat ubah
    expect(screen.queryByTestId('variant-card')).not.toBeInTheDocument();
  });

  it('shows the item-level base price (first variant) in edit mode and keeps it in sync with the table', async () => {
    const { user } = await openEdit();
    const base = screen.getByLabelText(/^harga dasar/i);
    const firstRow = screen.getByLabelText(P.f_variant_price_aria.replace('{n}', '1'));
    expect(base).toHaveValue(3000);

    await user.clear(base);
    await user.type(base, '3500');
    expect(firstRow).toHaveValue(3500);
    await user.click(screen.getByRole('button', { name: P.f_save_item }));

    await waitFor(() => expect(updateVariant).toHaveBeenCalled());
    expect(updateVariant.mock.calls[0][1]).toMatchObject({ sell_price: '3500.00' });
  });

  it('prefills the modifier table from the item\'s product-level links and lists category links read-only', async () => {
    await openEdit();
    await screen.findByTestId('create-modifiers');

    expect(screen.getAllByTestId('create-modifier-row')).toHaveLength(1);
    expect(screen.getByLabelText(P.f_modifier_group_aria.replace('{n}', '1'))).toHaveValue('7');
    expect(screen.getByLabelText(P.f_modifier_max_aria.replace('{n}', '1'))).toHaveValue(3);
    const inherited = screen.getByTestId('inherited-links');
    expect(within(inherited).getByText('Ukuran')).toBeInTheDocument();
    expect(within(inherited).queryByRole('combobox')).not.toBeInTheDocument();
    expect(within(inherited).queryByRole('textbox')).not.toBeInTheDocument();
    expect(within(inherited).queryByRole('spinbutton')).not.toBeInTheDocument();
  });

  it('does not touch the modifier links when nothing about them changed', async () => {
    const { user } = await openEdit();
    await screen.findByTestId('create-modifiers');
    await user.click(screen.getByRole('button', { name: P.f_save_item }));

    await waitFor(() => expect(updateProduct).toHaveBeenCalled());
    expect(saveModifierLinks).not.toHaveBeenCalled();
  });

  it('sends ONLY the product-level rows when they changed, never the category ones', async () => {
    const { user } = await openEdit();
    await screen.findByTestId('create-modifiers');
    const max = screen.getByLabelText(P.f_modifier_max_aria.replace('{n}', '1'));
    await user.clear(max);
    await user.type(max, '2');
    await user.click(screen.getByRole('button', { name: P.f_save_item }));

    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    expect(saveModifierLinks).toHaveBeenCalledWith(1, [{ group_id: 7, is_required: false, min_select: 0, max_select: 2, display_order: 1 }]);
  });

  it('can unlink the last product-level group, which sends an empty list', async () => {
    const { user } = await openEdit();
    await screen.findByTestId('create-modifiers');
    await user.click(screen.getByRole('button', { name: P.f_modifier_remove_aria.replace('{n}', '1') }));
    await user.click(screen.getByRole('button', { name: P.f_save_item }));
    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledWith(1, []));
  });

  it('validates the modifier rules before saving anything (min above max)', async () => {
    const { user } = await openEdit();
    await screen.findByTestId('create-modifiers');
    const min = screen.getByLabelText(P.f_modifier_min_aria.replace('{n}', '1'));
    await user.clear(min);
    await user.type(min, '5');
    await user.click(screen.getByRole('button', { name: P.f_save_item }));

    expect(await screen.findByText(P.ml_err_max)).toBeInTheDocument();
    expect(updateProduct).not.toHaveBeenCalled();
  });

  it('shows a toast but keeps the item saved when the modifier save fails', async () => {
    saveModifierLinks.mockRejectedValue(new Error('boom'));
    const { user, saved } = await openEdit();
    await screen.findByTestId('create-modifiers');
    await user.click(screen.getByRole('button', { name: P.f_modifier_remove_aria.replace('{n}', '1') }));
    await user.click(screen.getByRole('button', { name: P.f_save_item }));

    await waitFor(() => expect(saved).toHaveBeenCalledWith({ id: 1, created: false }));
    expect(toasts()).toContain(P.f_links_failed);
  });

  it('hides the modifier section, and never sends links, when the group list cannot be read', async () => {
    listModifierGroups.mockRejectedValue(Object.assign(new Error('forbidden'), { status: 403 }));
    const { user } = await openEdit();
    await user.click(screen.getByRole('button', { name: P.f_save_item }));

    await waitFor(() => expect(updateProduct).toHaveBeenCalled());
    expect(screen.queryByRole('heading', { name: P.f_sec_modifier })).not.toBeInTheDocument();
    expect(saveModifierLinks).not.toHaveBeenCalled();
  });

  it('keeps the save order: product, then each variant, then the modifier links', async () => {
    const order = [];
    updateProduct.mockImplementation(async () => { order.push('product'); return {}; });
    updateVariant.mockImplementation(async () => { order.push('variant'); return { id: 10 }; });
    saveModifierLinks.mockImplementation(async () => { order.push('links'); return { applied: true }; });
    const { user } = await openEdit();
    await screen.findByTestId('create-modifiers');
    await user.click(screen.getByRole('button', { name: P.f_modifier_remove_aria.replace('{n}', '1') }));
    await user.click(screen.getByRole('button', { name: P.f_save_item }));

    await waitFor(() => expect(order).toEqual(['product', 'variant', 'links']));
  });
});
