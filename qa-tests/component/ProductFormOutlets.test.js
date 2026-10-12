import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/vue';
import { renderDrawerHost } from './productFormDrawerHost';
import { getProduct, createProduct, saveVariants, saveModifierLinks } from '../../resources/js/api/products';
import { listStations } from '../../resources/js/api/stations';
import { listModifierGroups } from '../../resources/js/api/modifierGroups';
import { createAdjustment } from '../../resources/js/api/stock';

// 045 Stage 3 (T060) — "Aktif di outlet": semua outlet tercentang bawaannya; hanya tampil bila beberapa outlet terlihat.
vi.mock('../../resources/js/api/products', () => ({
  getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), addVariant: vi.fn(), updateVariant: vi.fn(),
  uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(), saveVariants: vi.fn(), saveModifierLinks: vi.fn(),
}));
vi.mock('../../resources/js/api/stock', () => ({ createAdjustment: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn() }));

const SCOPE = { outlets: [{ id: 1, name: 'Warkop WJM 1' }, { id: 2, name: 'Warkop WJM 2' }, { id: 3, name: 'Warkop WJM 3' }], multi: true };

async function openCreate(opts) {
  const host = renderDrawerHost(opts);
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: /^tambah item$/i }));
  await screen.findByTestId('product-form');
  return { user, ...host };
}

async function fillAndSave(user) {
  await user.type(screen.getByLabelText(/^nama item/i), 'Kopi Susu');
  await user.selectOptions(screen.getByLabelText(/^kategori menu/i), 'Kategori A');
  await user.type(screen.getByLabelText(/^harga dasar/i), '18000');
  await user.click(screen.getByRole('button', { name: /simpan item/i }));
}

describe('ProductFormDrawer outlets (045 Stage 3)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [] });
    listModifierGroups.mockResolvedValue({ data: [] });
    createProduct.mockResolvedValue({ id: 55, variants: [{ id: 501, variant_name: 'Standard' }] });
    saveVariants.mockResolvedValue({ applied: [], pending: [] });
    saveModifierLinks.mockResolvedValue({ applied: true });
    createAdjustment.mockResolvedValue({});
  });

  it('checks every outlet by default and sends the chosen set on create', async () => {
    const { user } = await openCreate({ scope: SCOPE });
    const group = await screen.findByTestId('active-outlets');
    for (const o of SCOPE.outlets) expect(within(group).getByLabelText(o.name)).toBeChecked();

    await user.click(within(group).getByLabelText('Warkop WJM 2'));
    await fillAndSave(user);

    await vi.waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(createProduct.mock.calls[0][0].outlet_ids).toEqual([1, 3]);
  });

  it('hides the group and sends no outlet_ids at a single outlet', async () => {
    const { user } = await openCreate({ scope: { outlets: [SCOPE.outlets[0]], multi: false } });
    expect(screen.queryByTestId('active-outlets')).not.toBeInTheDocument();

    await fillAndSave(user);

    await vi.waitFor(() => expect(createProduct).toHaveBeenCalled());
    expect(createProduct.mock.calls[0][0]).not.toHaveProperty('outlet_ids');
  });

  it('pre-fills the carried state when editing', async () => {
    getProduct.mockResolvedValue({
      id: 1, name: 'Keychain A', home_outlet_id: 1, category_id: 1, is_preorder: false, is_active: true, description: '',
      variants: [{ id: 10, sku: 'KA-001', variant_name: 'Standard', sell_price: '10000.00', cost_price: '0.00', current_stock: 0 }],
      outlets: [{ id: 1, name: 'Warkop WJM 1', carried: true }, { id: 2, name: 'Warkop WJM 2', carried: false }, { id: 3, name: 'Warkop WJM 3', carried: true }],
    });
    renderDrawerHost({ scope: SCOPE });
    const { default: userEvent } = await import('@testing-library/user-event');
    await userEvent.setup().click(screen.getByRole('button', { name: /ubah keychain a/i }));

    const group = await screen.findByTestId('active-outlets');
    expect(within(group).getByLabelText('Warkop WJM 1')).toBeChecked();
    expect(within(group).getByLabelText('Warkop WJM 2')).not.toBeChecked();
    expect(within(group).getByLabelText('Warkop WJM 3')).toBeChecked();
  });
});
