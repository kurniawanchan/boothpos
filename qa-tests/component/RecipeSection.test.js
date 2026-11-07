import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import RecipeSection from '../../resources/js/components/product/RecipeSection.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useToastStore } from '../../resources/js/stores/toast';
import { listRecipes, getRecipe, createRecipe, updateRecipe, recalculateRecipeCost, removeRecipe, removeOneRecipe } from '../../resources/js/api/recipes';
import { listMaterials, getMaterial } from '../../resources/js/api/materials';

vi.mock('../../resources/js/api/recipes', () => ({
  listRecipes: vi.fn(), getRecipe: vi.fn(), createRecipe: vi.fn(), updateRecipe: vi.fn(), recalculateRecipeCost: vi.fn(), removeRecipe: vi.fn(), removeOneRecipe: vi.fn(),
  listRecipeModifierOptions: vi.fn().mockResolvedValue([]),
}));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn(), getMaterial: vi.fn() }));

const VARIANTS = [{ id: 1, sku: 'KOP-1', variant_name: 'Panas', sell_price: '50000.00', is_active: true, bom_complete: false }];
// 089: resep tanpa versi; dua resep aktif untuk satu varian (dijumlahkan saat jual) + satu arsip sisa versi lama.
const RECIPES_WITH_COST = [
  { id: 30, name: 'Dasar', yield: '2.000', effective_from: '2026-10-01', note: 'kurangi susu', archived: false, created_by: 'Laila', variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }], is_active_now: true, portion_cost: '17500.00', incomplete: false, food_cost_pct: 35 },
  { id: 31, name: 'Topping', yield: '1.000', effective_from: '2026-10-05', note: null, archived: false, created_by: 'Owner', variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }], is_active_now: true, portion_cost: '5000.00', incomplete: true, food_cost_pct: null },
  { id: 20, name: 'Resep v1', yield: '2.000', effective_from: '2026-09-01', note: null, archived: true, created_by: null, variants: [], is_active_now: false, portion_cost: '19000.00', incomplete: false, food_cost_pct: 38 },
];
// Pengguna tanpa izin biaya: server tidak mengirim bidang biaya sama sekali.
const RECIPES_NO_COST = RECIPES_WITH_COST.map(({ portion_cost, incomplete, food_cost_pct, ...rest }) => rest);

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

function renderSection({ permissions = ['menu.recipe.edit'], recipe = null, variants = VARIANTS } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['products'], permissions };
  return render(RecipeSection, { props: { productId: 12, productName: 'Kopi Susu', recipe, variants }, global: { plugins: [pinia] } });
}

// 043 (T076), diubah 089 — bagian Resep pada halaman item: daftar resep tanpa versi.
describe('RecipeSection (043/089)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listRecipes.mockResolvedValue({ data: RECIPES_WITH_COST });
    getRecipe.mockResolvedValue({
      id: 30, name: 'Dasar', yield: '2.000', effective_from: '2026-10-01', note: null, variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }],
      lines: [{ material_id: 1, name: 'Biji kopi', unit: 'kg', kind: 'ingredient', quantity: '2.0000', shrinkage_pct: '0.00', unit_cost: '5000.0000', line_cost: '10000.0000' }],
    });
    listMaterials.mockResolvedValue({ data: [{ id: 1, name: 'Biji kopi', unit: 'kg', kind: 'ingredient' }], meta: { last_page: 1 } });
    getMaterial.mockResolvedValue({ id: 1, vendor_prices: [] });
    createRecipe.mockResolvedValue({ id: 40, name: 'Baru' });
    updateRecipe.mockResolvedValue({ id: 30, name: 'Dasar' });
    recalculateRecipeCost.mockResolvedValue({ updated: [{ variant_id: 1 }, { variant_id: 2 }], skipped: [] });
  });

  it('lists every recipe with name, portions, effective date, variants and cost; marks the active ones and the archived one', async () => {
    renderSection();
    const rows = await screen.findAllByTestId('recipe-row');
    expect(rows).toHaveLength(3);
    expect(rows[0]).toHaveTextContent('Dasar');
    expect(rows[0]).toHaveTextContent('kurangi susu');
    expect(rows[0]).toHaveTextContent('Panas');
    expect(within(rows[0]).getByTestId('recipe-active')).toHaveTextContent(/berlaku sekarang/i);
    expect(within(rows[1]).getByTestId('recipe-active')).toBeInTheDocument();
    expect(within(rows[2]).getByTestId('recipe-archived')).toHaveTextContent(/arsip/i);
    expect(within(rows[2]).queryByTestId('recipe-active')).not.toBeInTheDocument();
    expect(screen.queryByText(/^v\d/)).not.toBeInTheDocument();
    const costs = screen.getAllByTestId('recipe-cost');
    expect(costs[0]).toHaveTextContent('17.500');
    expect(costs[0]).toHaveTextContent('35%');
    expect(costs[1]).toHaveTextContent(/biaya belum lengkap/i);
    expect(screen.getByTestId('recipe-sum-note')).toHaveTextContent(/2 resep aktif.*dijumlahkan/i);
  }, 30000);

  it('renders NO cost column or cost figure when the payload carries none (a user without the cost permission)', async () => {
    listRecipes.mockResolvedValue({ data: RECIPES_NO_COST });
    renderSection({ permissions: [] });
    await screen.findAllByTestId('recipe-row');
    expect(screen.queryByTestId('recipe-cost')).not.toBeInTheDocument();
    expect(screen.queryByRole('columnheader', { name: /biaya/i })).not.toBeInTheDocument();
  }, 30000);

  it('hides Add, Edit, Remove and Recalculate for a user without menu.recipe.edit (hidden, not disabled)', async () => {
    renderSection({ permissions: ['menu.item.86'] });
    await screen.findAllByTestId('recipe-row');
    expect(screen.queryByTestId('recipe-add')).not.toBeInTheDocument();
    expect(screen.queryByTestId('recipe-edit')).not.toBeInTheDocument();
    expect(screen.queryByTestId('recipe-remove-one')).not.toBeInTheDocument();
    expect(screen.queryByTestId('recipe-remove')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /hitung ulang biaya/i })).not.toBeInTheDocument();
  }, 30000);

  it('an editor sees per-row Edit and Remove for live recipes only (archived is read-only), plus Add and Recalculate', async () => {
    renderSection();
    const rows = await screen.findAllByTestId('recipe-row');
    expect(screen.getByRole('button', { name: /tambah resep/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /hitung ulang biaya/i })).toBeInTheDocument();
    expect(within(rows[0]).getByTestId('recipe-edit')).toBeInTheDocument();
    expect(within(rows[1]).getByTestId('recipe-remove-one')).toBeInTheDocument();
    expect(within(rows[2]).queryByTestId('recipe-edit')).not.toBeInTheDocument();
    expect(within(rows[2]).queryByTestId('recipe-remove-one')).not.toBeInTheDocument();
  }, 30000);

  it('with no recipe yet: empty text, "Tambah resep" only, no recalculation or remove-all', async () => {
    listRecipes.mockResolvedValue({ data: [] });
    renderSection();
    expect(await screen.findByTestId('recipe-empty')).toHaveTextContent(/belum ada resep/i);
    expect(screen.getByRole('button', { name: /tambah resep/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /hitung ulang biaya/i })).not.toBeInTheDocument();
    expect(screen.queryByTestId('recipe-remove')).not.toBeInTheDocument();
  }, 30000);

  it('a failed load is an error with Retry, not "no recipes"', async () => {
    const user = await userEv();
    listRecipes.mockRejectedValueOnce(new Error('boom'));
    renderSection();
    const error = await screen.findByTestId('recipe-list-error');
    expect(error).toHaveTextContent(/tidak dapat dimuat/i);
    expect(screen.queryByTestId('recipe-empty')).not.toBeInTheDocument();
    await user.click(within(error).getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findAllByTestId('recipe-row')).toHaveLength(3);
  }, 30000);

  it('keeps the legacy BOM summary when there is no recipe', async () => {
    listRecipes.mockResolvedValue({ data: [] });
    renderSection({ variants: [{ id: 1, sku: 'A', variant_name: 'X', has_bom: true, bom_cost: '3000.00' }] });
    expect(await screen.findByTestId('bom-summary')).toHaveTextContent('3.000');
  }, 30000);

  it('"Hitung ulang biaya" asks first, then recalculates, reports the count and tells the page to reload', async () => {
    const user = await userEv();
    const view = renderSection();
    await screen.findAllByTestId('recipe-row');
    useToastStore().items = [];
    await user.click(screen.getByRole('button', { name: /hitung ulang biaya/i }));
    expect(recalculateRecipeCost).not.toHaveBeenCalled(); // konfirmasi dulu
    const dialog = await screen.findByRole('dialog', { name: /hitung ulang biaya resep/i });
    expect(dialog).toHaveTextContent(/penjualan yang sudah tercatat tidak berubah/i);
    await user.click(within(dialog).getByRole('button', { name: /^hitung ulang biaya$/i }));

    await waitFor(() => expect(recalculateRecipeCost).toHaveBeenCalledWith(12));
    await waitFor(() => expect(view.emitted().changed).toHaveLength(1));
    expect(useToastStore().items.map((i) => i.message).join(' ')).toMatch(/biaya 2 varian dihitung ulang/i);
    expect(listRecipes).toHaveBeenCalledTimes(2); // daftar dimuat ulang
  }, 30000);

  it('warns about variants skipped because their cost is incomplete', async () => {
    const user = await userEv();
    recalculateRecipeCost.mockResolvedValueOnce({ updated: [], skipped: [{ variant_id: 1, reason: 'incomplete' }] });
    renderSection();
    await screen.findAllByTestId('recipe-row');
    await user.click(screen.getByRole('button', { name: /hitung ulang biaya/i }));
    await user.click(within(await screen.findByRole('dialog', { name: /hitung ulang biaya resep/i })).getByRole('button', { name: /^hitung ulang biaya$/i }));
    await waitFor(() => expect(useToastStore().items.map((i) => i.message).join(' ')).toMatch(/1 varian dilewati/i));
  }, 30000);

  it('"Ubah" on a row opens the drawer with THAT recipe; saving updates in place, reloads the list and tells the page', async () => {
    const user = await userEv();
    const view = renderSection();
    const rows = await screen.findAllByTestId('recipe-row');
    await user.click(within(rows[0]).getByTestId('recipe-edit'));
    const drawer = await screen.findByRole('dialog', { name: /ubah resep/i });
    await within(drawer).findByTestId('rc-stock-note');
    expect(getRecipe).toHaveBeenCalledWith(30);
    await user.click(within(drawer).getByRole('button', { name: /simpan resep/i }));

    await waitFor(() => expect(updateRecipe).toHaveBeenCalledTimes(1));
    expect(updateRecipe.mock.calls[0][0]).toBe(30);
    expect(createRecipe).not.toHaveBeenCalled();
    await waitFor(() => expect(view.emitted().changed).toHaveLength(1));
    expect(listRecipes).toHaveBeenCalledTimes(2);
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /ubah resep/i })).not.toBeInTheDocument());
  }, 30000);

  it('"Tambah resep" opens an EMPTY drawer (no getRecipe) and saving creates a new recipe without touching the others', async () => {
    const user = await userEv();
    const view = renderSection();
    await screen.findAllByTestId('recipe-row');
    await user.click(screen.getByRole('button', { name: /tambah resep/i }));
    const drawer = await screen.findByRole('dialog', { name: /^resep$/i });
    await within(drawer).findByTestId('rc-stock-note');
    expect(getRecipe).not.toHaveBeenCalled();
    const name = within(drawer).getByLabelText(/nama resep/i);
    expect(name).toHaveValue('Kopi Susu'); // 092: resep baru memakai nama produk sebagai bawaan
    await user.clear(name);
    await user.type(name, 'Baru');
    await user.click(within(drawer).getByTestId('rc-add'));
    await user.click(within(drawer).getByRole('combobox', { name: 'Bahan 1' }));
    await user.click(await screen.findByRole('option', { name: 'Biji kopi' })); // panel pemilih di-teleport ke <body>
    await user.type(within(drawer).getByLabelText('Jumlah bahan 1'), '1');
    await user.click(within(drawer).getByRole('button', { name: /simpan resep/i }));

    await waitFor(() => expect(createRecipe).toHaveBeenCalledTimes(1));
    expect(createRecipe.mock.calls[0][0]).toBe(12);
    expect(createRecipe.mock.calls[0][1]).toMatchObject({ name: 'Baru' });
    expect(createRecipe.mock.calls[0][1].blocks.map((b) => b.variant_id)).toEqual([1]);
    expect(updateRecipe).not.toHaveBeenCalled();
    await waitFor(() => expect(view.emitted().changed).toHaveLength(1));
  }, 30000);

  // 089 — hapus SATU resep, dengan konfirmasi yang menyebut namanya.
  it('removes ONE recipe after confirmation and reloads', async () => {
    const user = await userEv();
    removeOneRecipe.mockResolvedValue({ removed: 1 });
    const { emitted } = renderSection();
    const rows = await screen.findAllByTestId('recipe-row');

    await user.click(within(rows[1]).getByTestId('recipe-remove-one'));
    expect(removeOneRecipe).not.toHaveBeenCalled();
    const dialog = await screen.findByRole('dialog', { name: 'Hapus resep ini?' });
    expect(dialog).toHaveTextContent('Topping');
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(removeOneRecipe).toHaveBeenCalledWith(31));
    expect(removeRecipe).not.toHaveBeenCalled();
    await waitFor(() => expect(emitted().changed).toBeTruthy());
  }, 30000);

  // 2026-10-31 (permintaan pemilik) — hapus SEMUA resep, dengan konfirmasi; tersembunyi tanpa izin edit resep.
  it('removes ALL recipes after confirmation and reloads', async () => {
    const user = await userEv();
    removeRecipe.mockResolvedValue({ removed: 3 });
    const { emitted } = renderSection();
    await screen.findAllByTestId('recipe-row');

    await user.click(screen.getByTestId('recipe-remove'));
    const dialog = await screen.findByRole('dialog', { name: 'Hapus semua resep?' });
    expect(dialog).toHaveTextContent(/3 resep/i);
    await user.click(within(dialog).getByRole('button', { name: 'Hapus semua resep' }));

    await waitFor(() => expect(removeRecipe).toHaveBeenCalledWith(12));
    await waitFor(() => expect(emitted().changed).toBeTruthy());
  }, 30000);
});
