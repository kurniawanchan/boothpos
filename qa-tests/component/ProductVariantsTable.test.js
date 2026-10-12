import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within, fireEvent } from '@testing-library/vue';
import { renderDrawerHost } from './productFormDrawerHost';
import { getProduct, updateProduct, updateVariant, uploadVariantImage } from '../../resources/js/api/products';
import { createAdjustment } from '../../resources/js/api/stock';
import id from '../../resources/js/locales/id.json';

vi.mock('../../resources/js/api/products', () => ({
  getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), addVariant: vi.fn(), updateVariant: vi.fn(),
  uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(), saveVariants: vi.fn(), saveModifierLinks: vi.fn(),
}));
vi.mock('../../resources/js/api/stock', () => ({ createAdjustment: vi.fn(), listMovements: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listBomLines: vi.fn(), copyBomFrom: vi.fn(), copyBomOut: vi.fn(), saveBomQuantities: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

const M = id.master_data;
const P = id.products;
const PRODUCT = {
  id: 2, home_outlet_id: 1, category_id: 1, name: 'MCYT', code_prefix: 'SPF-KC-MCY', description: null, is_preorder: false, is_active: true, image_url: null,
  variants: [
    { id: 10, sku: 'SPF-KC-MCY-001', variant_name: 'Red', cost_price: '1000.00', sell_price: '2500.00', current_stock: 5, low_stock_alert: null, is_active: true, track_stock: true, has_bom: true, bom_cost: '397.00', bom_complete: false, image_url: null },
    { id: 11, sku: 'SPF-KC-MCY-002', variant_name: 'Blue', cost_price: '1000.00', sell_price: '800.00', current_stock: 9, low_stock_alert: null, is_active: true, track_stock: true, has_bom: false, bom_cost: null, bom_complete: false, image_url: 'https://example.test/blue.png' },
    { id: 12, sku: 'SPF-KC-MCY-003', variant_name: 'Green', cost_price: '397.00', sell_price: '1000.00', current_stock: 2, low_stock_alert: null, is_active: true, track_stock: true, has_bom: true, bom_cost: '397.00', bom_complete: true, image_url: null },
  ],
};

// 043: laci UBAH dibuka dari halaman item (bukan lagi dari daftar), jadi dipasang lewat inang yang meniru tombol itu.
async function openEdit(menus = ['dashboard', 'products', 'purchase_orders']) {
  renderDrawerHost({ menus, product: { id: 2, name: 'MCYT' } });
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  await user.click(screen.getAllByRole('button', { name: /^ubah /i })[0]);
  await screen.findAllByTestId('variant-row');

  return user;
}

const rows = () => screen.getAllByTestId('variant-row');
const n = (key, i) => P[key].replace('{n}', String(i + 1));
const before = (a, b) => Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
const save = (user) => user.click(screen.getByRole('button', { name: P.f_save_item }));

// 037 (kartu varian) -> 043: tabel varian yang sama dengan mode buat, ditambah kolom dan aksi baris khusus mode ubah.
describe('Variants table in the Edit item drawer (043, replaces the 037 variant cards)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getProduct.mockResolvedValue(PRODUCT);
    updateProduct.mockResolvedValue({});
    updateVariant.mockImplementation(async (variantId) => ({ id: variantId }));
    createAdjustment.mockResolvedValue({});
  });

  it('shows every variant as a table row in the same drawer width as create mode', async () => {
    await openEdit();

    expect(rows()).toHaveLength(3);
    expect(screen.getByRole('dialog')).toHaveClass('max-w-[1000px]');
    expect(screen.queryByTestId('variant-card')).not.toBeInTheDocument();
  });

  it('has the columns Variant, SKU, Image, Cost, Base price, Stock, Active (cost only for users who may see it)', async () => {
    await openEdit();
    const headers = within(screen.getByTestId('variants-table')).getAllByRole('columnheader').map((h) => h.textContent.trim()).filter(Boolean);

    expect(headers).toEqual([P.f_col_variant, P.f_col_sku, P.f_col_image, P.f_col_cost, P.f_col_price, P.f_col_stock_now, P.f_col_active]);
  });

  it('hides the cost column, the markup field and the apply-markup action for a user without cost access, and never sends cost_price', async () => {
    const user = await openEdit(['dashboard']);

    expect(screen.queryByRole('columnheader', { name: P.f_col_cost })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(n('f_variant_cost_aria', 0))).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/^Markup/)).not.toBeInTheDocument();
    expect(within(rows()[0]).queryByRole('button', { name: M.apply_markup })).not.toBeInTheDocument();
    expect(within(rows()[0]).queryByText(/^markup /)).not.toBeInTheDocument();

    await save(user);
    await waitFor(() => expect(updateVariant).toHaveBeenCalledTimes(3));
    for (const call of updateVariant.mock.calls) expect(call[1]).not.toHaveProperty('cost_price');
  });

  it('shows the real SKU for a saved variant and "Otomatis" for a new one', async () => {
    const user = await openEdit();

    expect(within(rows()[0]).getByText('SPF-KC-MCY-001')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    expect(within(rows()[3]).getByText(P.f_sku_auto)).toBeInTheDocument();
  });

  it('shows markup, margin and BOM cost as muted text under each row (negative values flagged), no coloured chips', async () => {
    await openEdit();
    const extra = within(rows()[0]);

    expect(extra.getByText(M.markup_value.replace('{value}', '150'))).toBeInTheDocument();
    expect(extra.getByText(M.margin.replace('{value}', '60'))).toBeInTheDocument();
    expect(extra.getByText(M.bom_cost_label.replace('{cost}', 'Rp 397'))).toBeInTheDocument();
    expect(extra.getByText(M.margin.replace('{value}', '60')).className).not.toMatch(/bg-/);

    const blue = within(rows()[1]);
    expect(blue.getByText(M.markup_value.replace('{value}', '-20'))).toHaveClass('text-danger-text');
    expect(blue.getByText(M.margin.replace('{value}', '-25'))).toHaveClass('text-danger-text');
    expect(blue.queryByText(/Rp 397/)).not.toBeInTheDocument();
  });

  it('puts Open BOM, Apply markup and Duplicate in the row actions, in that order', async () => {
    await openEdit();
    const row = within(rows()[0]);

    const open = row.getByRole('button', { name: M.bom_open });
    const apply = row.getByRole('button', { name: M.apply_markup });
    const dup = row.getByRole('button', { name: M.duplicate_variant });

    expect(before(open, apply) && before(apply, dup)).toBe(true);
  });

  it('explains what a BOM is in a tooltip on the Open BOM button (hover and keyboard focus)', async () => {
    const user = await openEdit();
    const open = within(rows()[0]).getByRole('button', { name: M.bom_open });

    await user.hover(open);
    expect(screen.getByRole('tooltip')).toHaveTextContent(M.bom_open_tip);
    await user.unhover(open);

    open.focus();
    expect(await screen.findByRole('tooltip')).toBeVisible();
  });

  it('offers no Open BOM and no BOM cost text on a brand-new unsaved variant, but keeps Apply markup, Duplicate and remove', async () => {
    const user = await openEdit();

    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    const fresh = within(rows()[3]);

    expect(fresh.queryByRole('button', { name: M.bom_open })).not.toBeInTheDocument();
    expect(fresh.queryByText(/Biaya BOM|Dari BOM/)).not.toBeInTheDocument();
    expect(fresh.getByRole('button', { name: M.apply_markup })).toBeInTheDocument();
    expect(fresh.getByRole('button', { name: M.duplicate_variant })).toBeInTheDocument();
    expect(fresh.getByRole('button', { name: n('f_variant_remove_aria', 3) })).toBeInTheDocument();
  });

  it('never offers to delete a saved variant (only deactivate it); only unsaved rows get the × button', async () => {
    const user = await openEdit();

    expect(screen.queryByRole('button', { name: n('f_variant_remove_aria', 0) })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: P.f_add_variant }));
    await user.click(screen.getByRole('button', { name: n('f_variant_remove_aria', 3) }));
    expect(rows()).toHaveLength(3);
  });

  it('orders the cells variant name, cost price, sell price, stock', async () => {
    await openEdit();
    const row = within(rows()[0]);

    const name = row.getByLabelText(n('f_variant_name_aria', 0));
    const cost = row.getByLabelText(n('f_variant_cost_aria', 0));
    const sell = row.getByLabelText(n('f_variant_price_aria', 0));
    const stock = row.getByLabelText(n('f_variant_stock_now_aria', 0));

    expect(before(name, cost) && before(cost, sell) && before(sell, stock)).toBe(true);
  });

  it('shows a 40px thumbnail for a variant with a picture and a placeholder icon for one without', async () => {
    await openEdit();

    const img = within(rows()[1]).getByAltText(n('f_variant_image_alt', 1));
    expect(img).toHaveAttribute('src', 'https://example.test/blue.png');
    expect(img.parentElement).toHaveClass('h-10', 'w-10');
    const placeholder = rows()[0].querySelector('.ph-image').parentElement;
    expect(placeholder).toHaveClass('h-10', 'w-10');
  });

  it('still applies the markup to the sell price and still locks the cost price of a completed BOM', async () => {
    const user = await openEdit();
    const first = within(rows()[0]);

    await user.clear(first.getByLabelText(n('f_variant_price_aria', 0)));
    await user.click(first.getByRole('button', { name: M.apply_markup }));

    await waitFor(() => expect(first.getByLabelText(n('f_variant_price_aria', 0))).toHaveValue(2500)); // 1000 x (1 + 150%)
    expect(within(rows()[2]).getByLabelText(n('f_variant_cost_aria', 2))).toBeDisabled();
    expect(within(rows()[2]).getByText(M.bom_cost_price_locked_hint)).toBeInTheDocument();
    expect(within(rows()[2]).getByText(M.bom_from_bom)).toBeInTheDocument();
    expect(first.getByLabelText(n('f_variant_cost_aria', 0))).not.toBeDisabled();
  });

  it('applies the item-level markup percentage, taken from the section header', async () => {
    const user = await openEdit();
    const markup = screen.getByLabelText(/^Markup/);
    await user.clear(markup);
    await user.type(markup, '50');
    await user.click(within(rows()[0]).getByRole('button', { name: M.apply_markup }));

    expect(within(rows()[0]).getByLabelText(n('f_variant_price_aria', 0))).toHaveValue(1500);
  });

  it('carries low-stock alert and track-stock on a muted second line and sends them with the variant', async () => {
    const user = await openEdit();
    const row = within(rows()[0]);

    await user.type(row.getByLabelText(n('f_variant_low_aria', 0)), '3');
    await user.click(row.getByLabelText(n('f_variant_track_aria', 0)));
    expect(row.getByLabelText(n('f_variant_track_aria', 0))).not.toBeChecked();
    await save(user);

    await waitFor(() => expect(updateVariant).toHaveBeenCalled());
    expect(updateVariant.mock.calls.find((c) => c[0] === 10)[1]).toMatchObject({ low_stock_alert: 3, track_stock: false });
  });

  it('asks for the stock-adjustment reason only after a stock change, blocks the save without it, then records the delta', async () => {
    const user = await openEdit();
    expect(screen.queryByLabelText(new RegExp(M.stock_adjustment_reason))).not.toBeInTheDocument();

    const stock = within(rows()[0]).getByLabelText(n('f_variant_stock_now_aria', 0));
    await user.clear(stock);
    await user.type(stock, '8');
    await save(user);

    expect(await screen.findByText(M.stock_adjustment_reason_required)).toBeInTheDocument();
    expect(updateProduct).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText(new RegExp(M.stock_adjustment_reason)), 'hitung ulang');
    await save(user);

    await waitFor(() => expect(createAdjustment).toHaveBeenCalledWith({ reason: 'hitung ulang', items: [{ variant_id: 10, qty_change: 3 }] }));
  });

  it('dims an inactive saved variant and lets the Active box bring it back', async () => {
    const user = await openEdit();

    await user.click(within(rows()[0]).getByLabelText(n('f_variant_active_aria', 0)));
    expect(rows()[0]).toHaveClass('opacity-50');
    await user.click(within(rows()[0]).getByLabelText(n('f_variant_active_aria', 0)));
    expect(rows()[0]).not.toHaveClass('opacity-50');
  });
});

// 043 — gambar per varian (permintaan pemilik): kolom GAMBAR di tabel, pratinjau lokal, validasi, unggah saat simpan.
describe('Variant image column in the Edit item drawer (043)', () => {
  const realCreate = URL.createObjectURL;
  const realRevoke = URL.revokeObjectURL;

  beforeEach(() => {
    vi.clearAllMocks();
    URL.createObjectURL = vi.fn(() => 'blob:preview-1');
    URL.revokeObjectURL = vi.fn();
    getProduct.mockResolvedValue(PRODUCT);
    updateProduct.mockResolvedValue({});
    updateVariant.mockImplementation(async (variantId) => ({ id: variantId }));
    uploadVariantImage.mockResolvedValue({});
  });
  afterEach(() => {
    URL.createObjectURL = realCreate;
    URL.revokeObjectURL = realRevoke;
  });

  const fileInput = (i) => within(rows()[i]).getByLabelText(n('f_variant_image_aria', i));
  const png = (name = 'merah.png') => new File(['x'], name, { type: 'image/png' });

  it('opens the file chooser from the thumbnail button and previews the chosen file locally', async () => {
    const user = await openEdit();
    const click = vi.spyOn(fileInput(0), 'click');

    await user.click(within(rows()[0]).getByRole('button', { name: n('f_variant_image_pick_aria', 0) }));
    expect(click).toHaveBeenCalled();

    await user.upload(fileInput(0), png());
    expect(within(rows()[0]).getByAltText(n('f_variant_image_alt', 0))).toHaveAttribute('src', 'blob:preview-1');
  });

  it('uploads the chosen file for that variant after the item is saved, and only for rows that have one', async () => {
    const user = await openEdit();
    const file = png();
    await user.upload(fileInput(0), file);
    await save(user);

    await waitFor(() => expect(uploadVariantImage).toHaveBeenCalledWith(10, file));
    expect(uploadVariantImage).toHaveBeenCalledTimes(1);
  });

  it('shows a non-image file as a small error under the thumbnail, keeps the old picture and uploads nothing', async () => {
    const user = await openEdit();
    await fireEvent.change(fileInput(1), { target: { files: [new File(['x'], 'catatan.txt', { type: 'text/plain' })] } });

    expect(within(rows()[1]).getByRole('alert')).toHaveTextContent(id.master_data.image_must_be_image_generic);
    expect(within(rows()[1]).getByAltText(n('f_variant_image_alt', 1))).toHaveAttribute('src', 'https://example.test/blue.png');
    await save(user);
    await waitFor(() => expect(updateVariant).toHaveBeenCalled());
    expect(uploadVariantImage).not.toHaveBeenCalled();
  });

  it('refuses a file over 5 MB with the size message', async () => {
    const user = await openEdit();
    const big = new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'besar.png', { type: 'image/png' });
    await user.upload(fileInput(0), big);

    expect(within(rows()[0]).getByRole('alert')).toHaveTextContent(id.master_data.image_max_size_generic);
  });

  it('lets the user discard a just-chosen file, which also releases the preview', async () => {
    const user = await openEdit();
    await user.upload(fileInput(0), png());
    await user.click(within(rows()[0]).getByRole('button', { name: n('f_variant_image_clear_aria', 0) }));

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1');
    expect(within(rows()[0]).queryByAltText(n('f_variant_image_alt', 0))).not.toBeInTheDocument();
    await save(user);
    await waitFor(() => expect(updateVariant).toHaveBeenCalled());
    expect(uploadVariantImage).not.toHaveBeenCalled();
  });

  it('can still enlarge a saved picture from the thumbnail row', async () => {
    const user = await openEdit();
    await user.click(within(rows()[1]).getByRole('button', { name: M.enlarge_variant_image.replace('{name}', 'Blue') }));

    expect(screen.getByAltText('Blue')).toHaveAttribute('src', 'https://example.test/blue.png'); // lightbox milik drawer
  });

  it('still toasts the failure but keeps the item saved when an image upload fails', async () => {
    uploadVariantImage.mockRejectedValue(new Error('boom'));
    const user = await openEdit();
    await user.upload(fileInput(0), png());
    await save(user);

    await waitFor(() => expect(uploadVariantImage).toHaveBeenCalled());
    expect(updateProduct).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(screen.queryByTestId('product-form')).not.toBeInTheDocument());
  });
});
