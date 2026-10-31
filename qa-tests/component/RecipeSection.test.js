import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import RecipeSection from '../../resources/js/components/product/RecipeSection.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useToastStore } from '../../resources/js/stores/toast';
import { listRecipes, getRecipe, createRecipe, recalculateRecipeCost, removeRecipe } from '../../resources/js/api/recipes';
import { listMaterials, getMaterial } from '../../resources/js/api/materials';

vi.mock('../../resources/js/api/recipes', () => ({ listRecipes: vi.fn(), getRecipe: vi.fn(), createRecipe: vi.fn(), recalculateRecipeCost: vi.fn(), removeRecipe: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn(), getMaterial: vi.fn() }));

const VARIANTS = [{ id: 1, sku: 'KOP-1', variant_name: 'Panas', sell_price: '50000.00', is_active: true, bom_complete: false }];
const VERSIONS_WITH_COST = [
  { id: 30, version: 3, yield: '2.000', effective_from: '2026-10-01', note: 'kurangi susu', created_by: 'Laila', variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }], is_active_now: true, portion_cost: '17500.00', incomplete: false, food_cost_pct: 35 },
  { id: 20, version: 2, yield: '2.000', effective_from: '2026-09-01', note: null, created_by: 'Owner', variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }], is_active_now: false, portion_cost: '19000.00', incomplete: true, food_cost_pct: null },
];
// Pengguna tanpa izin biaya: server tidak mengirim bidang biaya sama sekali.
const VERSIONS_NO_COST = VERSIONS_WITH_COST.map(({ portion_cost, incomplete, food_cost_pct, ...rest }) => rest);
const RECIPE_SUMMARY = { version: 3, portion_cost: '17500.00', food_cost_pct: 35, gross_margin: '32500.00', incomplete: false, line_count: 2 };

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

function renderSection({ permissions = ['menu.recipe.edit'], recipe = RECIPE_SUMMARY, variants = VARIANTS } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['products'], permissions };
  return render(RecipeSection, { props: { productId: 12, productName: 'Kopi Susu', recipe, variants }, global: { plugins: [pinia] } });
}

// 043 (T076) — bagian Resep pada halaman item.
describe('RecipeSection (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listRecipes.mockResolvedValue({ data: VERSIONS_WITH_COST });
    getRecipe.mockResolvedValue({
      id: 30, version: 3, yield: '2.000', variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }],
      lines: [{ material_id: 1, name: 'Biji kopi', unit: 'kg', kind: 'ingredient', quantity: '2.0000', shrinkage_pct: '0.00', unit_cost: '5000.0000', line_cost: '10000.0000' }],
    });
    listMaterials.mockResolvedValue({ data: [{ id: 1, name: 'Biji kopi', unit: 'kg', kind: 'ingredient' }], meta: { last_page: 1 } });
    getMaterial.mockResolvedValue({ id: 1, vendor_prices: [] });
    createRecipe.mockResolvedValue({ id: 40, version: 4 });
    recalculateRecipeCost.mockResolvedValue({ updated: [{ variant_id: 1 }, { variant_id: 2 }], skipped: [] });
  });

  it('lists versions with effective date, "Berlaku sekarang" on the active one, variants, note and who; cost when the payload has it', async () => {
    renderSection();
    const rows = await screen.findAllByTestId('recipe-version-row');
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveTextContent('v3');
    expect(within(rows[0]).getByTestId('version-active')).toHaveTextContent(/berlaku sekarang/i);
    expect(within(rows[1]).queryByTestId('version-active')).not.toBeInTheDocument();
    expect(rows[0]).toHaveTextContent('Panas');
    expect(rows[0]).toHaveTextContent('kurangi susu');
    expect(rows[0]).toHaveTextContent('oleh Laila');
    const costs = screen.getAllByTestId('version-cost');
    expect(costs[0]).toHaveTextContent('17.500');
    expect(costs[0]).toHaveTextContent('35%');
    expect(costs[1]).toHaveTextContent('19.000');
    expect(costs[1]).toHaveTextContent(/biaya belum lengkap/i);
  }, 30000);

  it('renders NO cost column or cost figure when the payload carries none (a user without the cost permission)', async () => {
    listRecipes.mockResolvedValue({ data: VERSIONS_NO_COST });
    renderSection({ permissions: [], recipe: { version: 3, line_count: 2, yield: '2.000' } });
    await screen.findAllByTestId('recipe-version-row');
    expect(screen.queryByTestId('version-cost')).not.toBeInTheDocument();
    expect(screen.queryByRole('columnheader', { name: /biaya/i })).not.toBeInTheDocument();
    expect(screen.getByTestId('recipe-summary')).not.toHaveTextContent('Rp');
  }, 30000);

  it('hides "Edit resep" and "Hitung ulang biaya" for a user without menu.recipe.edit (hidden, not disabled)', async () => {
    renderSection({ permissions: ['menu.item.86'] });
    await screen.findAllByTestId('recipe-version-row');
    expect(screen.queryByRole('button', { name: /edit resep/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /buat resep/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /hitung ulang biaya/i })).not.toBeInTheDocument();
  }, 30000);

  it('shows both actions to a recipe editor; with no version yet it offers "Buat resep" and no recalculation', async () => {
    const first = renderSection();
    await screen.findAllByTestId('recipe-version-row');
    expect(screen.getByRole('button', { name: /edit resep/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /hitung ulang biaya/i })).toBeInTheDocument();
    first.unmount();

    listRecipes.mockResolvedValue({ data: [] });
    renderSection({ recipe: null });
    expect(await screen.findByTestId('recipe-versions-empty')).toBeInTheDocument();
    expect(screen.getByTestId('recipe-empty')).toHaveTextContent(/belum ada resep/i);
    expect(screen.getByRole('button', { name: /buat resep/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /hitung ulang biaya/i })).not.toBeInTheDocument();
  }, 30000);

  it('a failed versions load is an error with Retry (the summary stays), not "no versions"', async () => {
    const user = await userEv();
    listRecipes.mockRejectedValueOnce(new Error('boom'));
    renderSection();
    const error = await screen.findByTestId('recipe-versions-error');
    expect(error).toHaveTextContent(/gagal dimuat/i);
    expect(screen.queryByTestId('recipe-versions-empty')).not.toBeInTheDocument();
    expect(screen.getByTestId('recipe-summary')).toHaveTextContent('17.500');
    await user.click(within(error).getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findAllByTestId('recipe-version-row')).toHaveLength(2);
  }, 30000);

  it('keeps the active-recipe summary and the legacy BOM summary behaviour', async () => {
    const first = renderSection();
    const summary = await screen.findByTestId('recipe-summary');
    expect(summary).toHaveTextContent('35%');
    expect(summary).toHaveTextContent('32.500');
    first.unmount();

    listRecipes.mockResolvedValue({ data: [] });
    renderSection({ recipe: null, variants: [{ id: 1, sku: 'A', variant_name: 'X', has_bom: true, bom_cost: '3000.00' }] });
    expect(await screen.findByTestId('bom-summary')).toHaveTextContent('3.000');
  }, 30000);

  it('"Hitung ulang biaya" asks first, then recalculates, reports the count and tells the page to reload', async () => {
    const user = await userEv();
    const view = renderSection();
    await screen.findAllByTestId('recipe-version-row');
    useToastStore().items = [];
    await user.click(screen.getByRole('button', { name: /hitung ulang biaya/i }));
    expect(recalculateRecipeCost).not.toHaveBeenCalled(); // konfirmasi dulu
    const dialog = await screen.findByRole('dialog', { name: /hitung ulang biaya resep/i });
    expect(dialog).toHaveTextContent(/penjualan yang sudah tercatat tidak berubah/i);
    await user.click(within(dialog).getByRole('button', { name: /^hitung ulang biaya$/i }));

    await waitFor(() => expect(recalculateRecipeCost).toHaveBeenCalledWith(12));
    await waitFor(() => expect(view.emitted().changed).toHaveLength(1));
    expect(useToastStore().items.map((i) => i.message).join(' ')).toMatch(/biaya 2 varian dihitung ulang/i);
    expect(listRecipes).toHaveBeenCalledTimes(2); // daftar versi dimuat ulang
  }, 30000);

  it('warns about variants skipped because their cost is incomplete', async () => {
    const user = await userEv();
    recalculateRecipeCost.mockResolvedValueOnce({ updated: [], skipped: [{ variant_id: 1, reason: 'incomplete' }] });
    renderSection();
    await screen.findAllByTestId('recipe-version-row');
    await user.click(screen.getByRole('button', { name: /hitung ulang biaya/i }));
    await user.click(within(await screen.findByRole('dialog', { name: /hitung ulang biaya resep/i })).getByRole('button', { name: /^hitung ulang biaya$/i }));
    await waitFor(() => expect(useToastStore().items.map((i) => i.message).join(' ')).toMatch(/1 varian dilewati/i));
  }, 30000);

  it('"Edit resep" opens the drawer prefilled from the latest version; saving reloads the versions and tells the page', async () => {
    const user = await userEv();
    const view = renderSection();
    await screen.findAllByTestId('recipe-version-row');
    await user.click(screen.getByRole('button', { name: /edit resep/i }));
    const drawer = await screen.findByRole('dialog', { name: /^resep$/i });
    await within(drawer).findByTestId('rc-versioning');
    expect(getRecipe).toHaveBeenCalledWith(30);
    await user.click(within(drawer).getByRole('button', { name: /simpan sebagai v4/i }));

    await waitFor(() => expect(createRecipe).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(view.emitted().changed).toHaveLength(1));
    expect(listRecipes).toHaveBeenCalledTimes(2);
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /^resep$/i })).not.toBeInTheDocument());
  }, 30000);

  // 2026-10-31 (permintaan pemilik) — hapus resep, dengan konfirmasi; tersembunyi tanpa izin edit resep.
  it('removes the recipe after confirmation and reloads', async () => {
    removeRecipe.mockResolvedValue({ removed_versions: 2 });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = renderSection();
    await screen.findAllByTestId('recipe-version-row');

    await user.click(screen.getByTestId('recipe-remove'));
    const dialog = await screen.findByRole('dialog', { name: 'Hapus resep ini?' });
    await user.click(within(dialog).getByRole('button', { name: 'Hapus resep' }));

    await waitFor(() => expect(removeRecipe).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(emitted().changed).toBeTruthy());
  });

  it('hides Remove without the recipe permission', async () => {
    renderSection({ permissions: [] });
    await screen.findAllByTestId('recipe-version-row');
    expect(screen.queryByTestId('recipe-remove')).toBeNull();
  });
});
