import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ModifierLinksDrawer from '../../resources/js/components/product/ModifierLinksDrawer.vue';
import { renderDrawerHost } from './productFormDrawerHost';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { getProduct, saveModifierLinks, updateProduct, updateVariant } from '../../resources/js/api/products';
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
const row = (group_id, name, display_order, extra = {}) => ({
  group_id, name, source: 'category', has_rules: false, is_required: false, min_select: 0, max_select: 1, display_order, options: ['X'], ...extra,
});
// Data detail yang SAMA diberikan ke kedua permukaan: dua grup warisan dan satu yang sudah dilepas.
const INHERITED = [row(9, 'Suhu', 1), row(4, 'Es', 2)];
const EXCLUDED = [row(11, 'Gula', 3)];

// 086 / FR-006 — laci Grup modifier dan formulir Ubah item menampilkan dan mengubah keadaan yang SAMA.
describe('release/restore of inherited modifier groups — drawer and form agree', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [] });
    listModifierGroups.mockResolvedValue({ data: [{ id: 7, name: 'Lain', selection_type: 'single', is_required: false, min_select: 0, max_select: 1, is_active: true }] });
    getProduct.mockResolvedValue({
      id: 1, home_outlet_id: 1, category_id: 1, name: 'Keychain A', code_prefix: 'ART-KY-001', product_segment: 'KEY', description: '',
      is_active: true, image_url: null, short_name: null, tax_category: null, prep_minutes: null, is_retail: false,
      variants: [{ id: 10, sku: 'ART-KY-001-001', variant_name: 'Standard', cost_price: '1000.00', sell_price: '3000.00', current_stock: 5, low_stock_alert: null, is_active: true, track_stock: true, image_url: null }],
      modifier_links: INHERITED, modifier_excluded: EXCLUDED,
    });
    updateProduct.mockResolvedValue({});
    updateVariant.mockResolvedValue({ id: 10 });
    saveModifierLinks.mockResolvedValue({ applied: true });
  });

  const names = (testid) => screen.queryAllByTestId(testid).map((el) => el.querySelector('span').textContent.trim());

  async function drawerState() {
    const pinia = createPinia();
    setActivePinia(pinia);
    useSettingsStore().menuReleaseEnabled = false;
    const view = render(ModifierLinksDrawer, { props: { open: true, product: { id: 1, name: 'Keychain A' }, links: INHERITED, excluded: EXCLUDED }, global: { plugins: [pinia] } });
    const state = { inherited: names('im-inherited-row'), excluded: names('im-excluded-row') };
    view.unmount();

    return state;
  }

  async function formState() {
    renderDrawerHost();
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /^ubah keychain a$/i }));
    await screen.findByTestId('create-modifiers');

    return { user, inherited: names('im-inherited-row'), excluded: names('im-excluded-row') };
  }

  it('lists the same inherited and released groups on both surfaces for the same data', async () => {
    const drawer = await drawerState();
    const form = await formState();

    expect(drawer).toEqual({ inherited: ['Suhu', 'Es'], excluded: ['Gula'] });
    expect({ inherited: form.inherited, excluded: form.excluded }).toEqual(drawer);
  });

  it('releasing the same group on either surface sends the same exclusion list', async () => {
    // Laci
    const pinia = createPinia();
    setActivePinia(pinia);
    useSettingsStore().menuReleaseEnabled = false;
    const { default: userEvent } = await import('@testing-library/user-event');
    const u1 = userEvent.setup();
    const view = render(ModifierLinksDrawer, { props: { open: true, product: { id: 1, name: 'Keychain A' }, links: INHERITED, excluded: EXCLUDED }, global: { plugins: [pinia] } });
    await u1.click(screen.getByRole('button', { name: P.ml_exclude_aria.replace('{name}', 'Suhu') }));
    await u1.click(screen.getByRole('button', { name: P.ml_save }));
    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    const fromDrawer = saveModifierLinks.mock.calls[0][2];
    view.unmount();
    saveModifierLinks.mockClear();

    // Formulir
    const { user } = await formState();
    await user.click(within(screen.getByTestId('inherited-links')).getByRole('button', { name: P.ml_exclude_aria.replace('{name}', 'Suhu') }));
    await user.click(screen.getByRole('button', { name: P.f_save_item }));
    await waitFor(() => expect(saveModifierLinks).toHaveBeenCalledTimes(1));
    const fromForm = saveModifierLinks.mock.calls[0][2];

    expect(fromDrawer).toEqual([9, 11]);
    expect(fromForm).toEqual(fromDrawer);
  });
});
