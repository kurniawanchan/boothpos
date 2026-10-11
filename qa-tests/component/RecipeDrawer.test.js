import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import RecipeDrawer from '../../resources/js/components/product/RecipeDrawer.vue';
import { useToastStore } from '../../resources/js/stores/toast';
import { createRecipe, getRecipe } from '../../resources/js/api/recipes';
import { getMaterial, listMaterials } from '../../resources/js/api/materials';
import { ApiError } from '../../resources/js/utils/errors';
import { computeRecipeCost, referencePrice } from '../../resources/js/utils/recipeCost';

vi.mock('../../resources/js/api/recipes', () => ({ createRecipe: vi.fn(), getRecipe: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn(), getMaterial: vi.fn() }));

const pad = (n) => String(n).padStart(2, '0');
const key = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const TODAY = key(new Date());
const daysAgo = (n) => {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return key(d);
};

const PRODUCT = {
  id: 12,
  name: 'Kopi Susu',
  variants: [
    { id: 1, sku: 'KOP-1', variant_name: 'Panas', sell_price: '50000.00', is_active: true, bom_complete: false },
    { id: 2, sku: 'KOP-2', variant_name: 'Es', sell_price: '60000.00', is_active: true, bom_complete: true },
  ],
};
const MATERIALS = [
  { id: 1, name: 'Biji kopi', unit: 'kg', kind: 'ingredient', standard_cost: null },
  { id: 2, name: 'Susu', unit: 'liter', kind: 'ingredient', standard_cost: null },
  { id: 3, name: 'Sirup gula', unit: 'ml', kind: 'semi_finished', standard_cost: '12.5000' },
  { id: 4, name: 'Gelas', unit: 'pcs', kind: 'packaging', standard_cost: null },
];
const VERSIONS = [
  { id: 30, version: 3, effective_from: '2026-10-01', variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }] },
  { id: 20, version: 2, effective_from: '2026-09-01', variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }] },
];
// Contoh hitung tangan: 2 × 5.000 + 0,5 @ 20% × 40.000 = 10.000 + 25.000 = 35.000; yield 2 → 17.500; harga 50.000 → 35,0%.
const RECIPE_V3 = {
  id: 30,
  version: 3,
  yield: '2.000',
  variants: [{ id: 1, sku: 'KOP-1', name: 'Panas' }],
  lines: [
    { material_id: 1, name: 'Biji kopi', unit: 'kg', kind: 'ingredient', quantity: '2.0000', shrinkage_pct: '0.00', unit_cost: '5000.0000', line_cost: '10000.0000' },
    { material_id: 2, name: 'Susu', unit: 'liter', kind: 'ingredient', quantity: '0.5000', shrinkage_pct: '20.00', unit_cost: '40000.0000', line_cost: '25000.0000' },
    { material_id: 4, name: 'Gelas', unit: 'pcs', kind: 'packaging', quantity: '1.0000', shrinkage_pct: '0.00', unit_cost: '0.0000', line_cost: '0.0000' },
  ],
};

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

async function renderDrawer({ versions = VERSIONS, product = PRODUCT } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const view = render(RecipeDrawer, { props: { open: true, product, versions }, global: { plugins: [pinia] } });
  await screen.findByTestId('rc-versioning');
  return view;
}

const lineRows = () => screen.queryAllByTestId('rc-line');
const submitBtn = (name) => screen.getByRole('button', { name });

// 043 (T076) — laci Resep.
describe('RecipeDrawer (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMaterials.mockResolvedValue({ data: MATERIALS, meta: { current_page: 1, last_page: 1 } });
    getRecipe.mockResolvedValue(RECIPE_V3);
    getMaterial.mockResolvedValue({ ...MATERIALS[0], vendor_prices: [] });
    createRecipe.mockResolvedValue({ id: 40, version: 4 });
  });

  describe('cost maths (pure helper, same formula as the server)', () => {
    it('matches the hand calculation and refuses to treat an unknown price as zero', () => {
      const lines = [
        { quantity: 2, shrinkage_pct: 0, unit_cost: 5000 },
        { quantity: 0.5, shrinkage_pct: 20, unit_cost: 40000 },
      ];
      const full = computeRecipeCost(lines, 2, 50000);
      expect(full.lineCosts).toEqual([10000, 25000]);
      expect(full.portionCost).toBe(17500);
      expect(full.foodCostPct).toBe(35);
      expect(full.grossMargin).toBe(32500);
      expect(full.incomplete).toBe(false);

      const partial = computeRecipeCost([...lines, { quantity: 1, shrinkage_pct: 0, unit_cost: null }], 2, 50000);
      expect(partial.incomplete).toBe(true);
      expect(partial.unknownCount).toBe(1);
      expect(partial.portionCost).toBe(17500);
      expect(partial.foodCostPct).toBeNull();
      expect(partial.grossMargin).toBeNull();
    });

    it('picks the preferred vendor price, else the cheapest (same order as Material::referencePrice)', () => {
      expect(referencePrice([{ id: 1, price: '900.00', is_preferred: false }, { id: 2, price: '1200.00', is_preferred: true }])).toBe(1200);
      expect(referencePrice([{ id: 1, price: '900.00' }, { id: 2, price: '700.00' }])).toBe(700);
      expect(referencePrice([])).toBeNull();
    });
  });

  it('first recipe: says saving creates a NEW version without deducting stock, labels the button v1, skips BOM-complete variants', async () => {
    await renderDrawer({ versions: [] });
    expect(getRecipe).not.toHaveBeenCalled();
    const sentence = screen.getByTestId('rc-versioning');
    expect(sentence).toHaveTextContent(/versi baru/i);
    expect(sentence).toHaveTextContent(/versi sebelumnya tetap tersimpan/i);
    expect(sentence).toHaveTextContent(/tidak mengurangi stok/i);
    expect(submitBtn(/simpan sebagai v1/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/panas/i)).toBeChecked();
    expect(screen.getByLabelText(/es/i, { selector: 'input[type=checkbox]' })).not.toBeChecked();
    expect(screen.getByTestId('rc-bom-note')).toHaveTextContent(/bom selesai/i);
    expect(screen.getByLabelText(/berlaku mulai/i)).toHaveValue(TODAY);
  }, 30000);

  it('prefills from the LATEST version into ONE table (unit of each material shown), and labels the button v{latest+1}', async () => {
    await renderDrawer();
    expect(getRecipe).toHaveBeenCalledWith(30);
    expect(submitBtn(/simpan sebagai v4/i)).toBeInTheDocument();
    expect(lineRows()).toHaveLength(3);
    expect(within(lineRows()[0]).getByTestId('rc-unit')).toHaveTextContent('kg');
    expect(within(lineRows()[2]).getByTestId('rc-unit')).toHaveTextContent('pcs');
    expect(screen.getByLabelText('Bahan 1')).toHaveValue('1');
    expect(screen.getByLabelText('Bahan 3')).toHaveValue('4');
    expect(screen.getByLabelText('Jumlah bahan 2')).toHaveValue(0.5);
    expect(screen.getByLabelText('Susut % bahan 2')).toHaveValue(20);
    expect(screen.getByLabelText(/hasil \(yield\)/i)).toHaveValue(2);
    expect(screen.getByLabelText(/panas/i)).toBeChecked();
  }, 30000);

  it('computes the live cost preview exactly (35.000 → 17.500 per portion → 35,0% food cost) and follows edits', async () => {
    const user = await userEv();
    await renderDrawer();
    expect(screen.getByTestId('rc-portion-cost')).toHaveTextContent('Rp 17.500');
    expect(screen.getByTestId('rc-food-cost')).toHaveTextContent('35.0%');
    expect(screen.getByTestId('rc-margin')).toHaveTextContent('Rp 32.500');
    const costs = screen.getAllByTestId('rc-line-cost').map((n) => n.textContent.trim());
    expect(costs).toEqual(['Rp 10.000', 'Rp 25.000', 'Rp 0']);
    expect(screen.queryByTestId('rc-incomplete')).not.toBeInTheDocument();

    const qty = screen.getByLabelText('Jumlah bahan 1');
    await user.clear(qty);
    await user.type(qty, '4'); // 4 × 5.000 = 20.000 + 25.000 = 45.000 ÷ 2 = 22.500 → 45,0%
    expect(screen.getByTestId('rc-portion-cost')).toHaveTextContent('Rp 22.500');
    expect(screen.getByTestId('rc-food-cost')).toHaveTextContent('45.0%');

    const yieldInput = screen.getByLabelText(/hasil \(yield\)/i);
    await user.clear(yieldInput);
    await user.type(yieldInput, '3'); // 45.000 ÷ 3 = 15.000 → 30,0%
    expect(screen.getByTestId('rc-portion-cost')).toHaveTextContent('Rp 15.000');
    expect(screen.getByTestId('rc-food-cost')).toHaveTextContent('30.0%');
  }, 30000);

  it('a line with no known price is shown as "—", never as zero: partial portion cost + incomplete notice + no food cost/margin', async () => {
    getRecipe.mockResolvedValue({ ...RECIPE_V3, lines: [RECIPE_V3.lines[0], { ...RECIPE_V3.lines[1], unit_cost: null, line_cost: null }] });
    await renderDrawer();
    expect(screen.getByTestId('rc-incomplete')).toHaveTextContent(/biaya belum lengkap: 1 bahan/i);
    expect(screen.getAllByTestId('rc-line-cost').map((n) => n.textContent.trim())).toEqual(['Rp 10.000', '—']);
    expect(screen.getByText(/biaya porsi \(sebagian\)/i)).toBeInTheDocument();
    expect(screen.getByTestId('rc-portion-cost')).toHaveTextContent('Rp 5.000');
    expect(screen.getByTestId('rc-food-cost')).toHaveTextContent('—');
    expect(screen.getByTestId('rc-margin')).toHaveTextContent('—');
  }, 30000);

  it('adding an ingredient fetches its reference price; semi-finished falls back to standard_cost; a failed fetch stays "unknown"', async () => {
    const user = await userEv();
    await renderDrawer({ versions: [] });

    // bahan baku dengan harga vendor (preferensi menang atas yang termurah)
    getMaterial.mockResolvedValueOnce({ ...MATERIALS[1], vendor_prices: [{ id: 1, price: '30000.00', is_preferred: false }, { id: 2, price: '40000.00', is_preferred: true }] });
    await user.click(screen.getByTestId('rc-add'));
    await user.selectOptions(screen.getByRole('combobox', { name: 'Bahan 1' }), 'Susu');
    await user.type(screen.getByLabelText('Jumlah bahan 1'), '2');
    expect(getMaterial).toHaveBeenCalledWith(2);
    await waitFor(() => expect(screen.getAllByTestId('rc-line-cost')[0]).toHaveTextContent('Rp 80.000'));
    expect(within(lineRows()[0]).getByTestId('rc-unit')).toHaveTextContent('liter');

    // setengah jadi tanpa harga vendor → standard_cost 12,5 × 10 = 125
    getMaterial.mockResolvedValueOnce({ ...MATERIALS[2], vendor_prices: [] });
    await user.click(screen.getByTestId('rc-add'));
    await user.selectOptions(screen.getByRole('combobox', { name: 'Bahan 2' }), 'Sirup gula');
    await user.type(screen.getByLabelText('Jumlah bahan 2'), '10');
    await waitFor(() => expect(within(lineRows()[1]).getByTestId('rc-line-cost')).toHaveTextContent('Rp 125'));

    // gagal memuat harga → tidak diketahui (bukan nol)
    getMaterial.mockRejectedValueOnce(new Error('network'));
    await user.click(screen.getByTestId('rc-add'));
    await user.selectOptions(screen.getByRole('combobox', { name: 'Bahan 3' }), 'Gelas');
    await user.type(screen.getByLabelText('Jumlah bahan 3'), '1');
    await waitFor(() => expect(screen.getByTestId('rc-incomplete')).toHaveTextContent(/1 bahan/));
    expect(within(lineRows()[2]).getByTestId('rc-line-cost')).toHaveTextContent('—');
  }, 30000);

  it('renders ONE table headed BAHAN PER PORSI, with the material select grouped by kind (<optgroup>) instead of three boxes', async () => {
    await renderDrawer({ versions: [] });
    expect(screen.getByText('Bahan per porsi')).toBeInTheDocument();
    expect(screen.getAllByRole('table')).toHaveLength(1);
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent.trim()).filter(Boolean)).toEqual(['Bahan / setengah jadi', 'Qty', 'UOM', 'Susut %', 'Biaya']);
    expect(screen.queryByTestId('rc-kind-ingredient')).not.toBeInTheDocument();

    const { default: userEvent } = await import('@testing-library/user-event');
    await userEvent.setup().click(screen.getByTestId('rc-add'));
    const select = screen.getByRole('combobox', { name: 'Bahan 1' });
    const groups = [...select.querySelectorAll('optgroup')].map((g) => ({ label: g.label, options: [...g.querySelectorAll('option')].map((o) => o.textContent.trim()) }));
    expect(groups).toEqual([
      { label: 'Bahan baku', options: ['Biji kopi', 'Susu'] },
      { label: 'Setengah jadi', options: ['Sirup gula'] },
      { label: 'Kemasan', options: ['Gelas'] },
    ]);
  }, 30000);

  it('"+ Tambah bahan" appends an empty row, "×" removes it, and the portion cost sits in the table footer', async () => {
    const user = await userEv();
    await renderDrawer();
    expect(lineRows()).toHaveLength(3);
    const footer = screen.getByTestId('rc-footer');
    expect(within(footer).getByText('+ Tambah bahan')).toBeInTheDocument();
    expect(within(footer).getByTestId('rc-portion-cost')).toHaveTextContent('Rp 17.500');
    expect(footer).toHaveTextContent(/biaya porsi\s*Rp 17\.500/i);

    await user.click(within(footer).getByTestId('rc-add'));
    expect(lineRows()).toHaveLength(4);
    expect(screen.getByLabelText('Bahan 4')).toHaveValue('');
    expect(within(lineRows()[3]).getByTestId('rc-line-cost')).toHaveTextContent('—');

    await user.click(screen.getByRole('button', { name: 'Hapus bahan 4' }));
    expect(lineRows()).toHaveLength(3);
    await user.click(screen.getByRole('button', { name: 'Hapus bahan 1' })); // 2 × 5.000 hilang → (25.000 + 0) ÷ 2
    expect(screen.getByTestId('rc-portion-cost')).toHaveTextContent('Rp 12.500');
  }, 30000);

  it('submits yield, effective date, variants, lines in kind order and the trimmed note; then emits saved and toasts the version', async () => {
    const user = await userEv();
    const view = await renderDrawer();
    useToastStore().items = [];
    const note = screen.getByLabelText(/catatan perubahan/i);
    await user.type(note, '  kurangi susu  ');
    await user.click(submitBtn(/simpan sebagai v4/i));

    await waitFor(() => expect(createRecipe).toHaveBeenCalledTimes(1));
    expect(createRecipe).toHaveBeenCalledWith(12, {
      yield: 2,
      effective_from: TODAY,
      variant_ids: [1],
      lines: [
        { material_id: 1, quantity: 2, shrinkage_pct: 0 },
        { material_id: 2, quantity: 0.5, shrinkage_pct: 20 },
        { material_id: 4, quantity: 1, shrinkage_pct: 0 },
      ],
      note: 'kurangi susu',
    });
    await waitFor(() => expect(view.emitted().saved).toHaveLength(1));
    expect(useToastStore().items.map((i) => i.message).join(' ')).toMatch(/resep v4 disimpan/i);
  }, 30000);

  it('refuses invalid input before calling the API, naming each field', async () => {
    const user = await userEv();
    await renderDrawer({ versions: [] });
    await user.clear(screen.getByLabelText(/hasil \(yield\)/i));
    await user.type(screen.getByLabelText(/hasil \(yield\)/i), '0');
    await user.click(screen.getByLabelText(/panas/i)); // tidak ada varian
    await user.click(submitBtn(/simpan sebagai v1/i));
    expect(await screen.findByText(/hasil harus lebih dari 0/i)).toBeInTheDocument();
    expect(screen.getByText(/pilih minimal satu varian/i)).toBeInTheDocument();
    expect(screen.getByText(/tambahkan minimal satu bahan/i)).toBeInTheDocument();
    expect(createRecipe).not.toHaveBeenCalled();

    // baris: bahan belum dipilih, jumlah 0, susut 100
    await user.click(screen.getByLabelText(/panas/i));
    await user.click(screen.getByTestId('rc-add'));
    await user.type(screen.getByLabelText('Jumlah bahan 1'), '0');
    const shrink = screen.getByLabelText('Susut % bahan 1');
    await user.clear(shrink);
    await user.type(shrink, '100');
    await user.click(submitBtn(/simpan sebagai v1/i));
    expect(await screen.findByText(/pilih bahan\./i)).toBeInTheDocument();
    expect(screen.getByText(/jumlah harus lebih dari 0/i)).toBeInTheDocument();
    expect(screen.getByText(/susut harus antara 0 dan 99,99/i)).toBeInTheDocument();
    expect(createRecipe).not.toHaveBeenCalled();
  }, 30000);

  it('refuses an effective date more than 30 days in the past', async () => {
    const user = await userEv();
    await renderDrawer();
    const { fireEvent } = await import('@testing-library/vue');
    await fireEvent.update(screen.getByLabelText(/berlaku mulai/i), daysAgo(45));
    await user.click(submitBtn(/simpan sebagai v4/i));
    expect(await screen.findByText(/tanggal berlaku wajib diisi dan paling lama 30 hari ke belakang/i)).toBeInTheDocument();
    expect(createRecipe).not.toHaveBeenCalled();
  }, 30000);

  it('shows server 422 errors on the right line (lines.N.quantity) and keeps the drawer open', async () => {
    const user = await userEv();
    const view = await renderDrawer();
    createRecipe.mockRejectedValueOnce(new ApiError('invalid', { status: 422, errors: { 'lines.1.quantity': ['Jumlah ditolak server.'], yield: ['Yield ditolak server.'] } }));
    await user.click(submitBtn(/simpan sebagai v4/i));
    const err = await screen.findByText('Jumlah ditolak server.');
    expect(err.closest('[data-testid="rc-line"]')).toHaveTextContent('liter');
    expect(screen.getByText('Yield ditolak server.')).toBeInTheDocument();
    expect(view.emitted().saved).toBeUndefined();
  }, 30000);

  it('surfaces a 409 cost_source_conflict with the server message and the offending SKUs', async () => {
    const user = await userEv();
    const view = await renderDrawer();
    createRecipe.mockRejectedValueOnce(new ApiError('Varian memakai biaya BOM.', { status: 409, code: 'cost_source_conflict', data: { variants: [{ id: 2, sku: 'KOP-2' }] } }));
    await user.click(submitBtn(/simpan sebagai v4/i));
    const alert = await screen.findByTestId('rc-alert');
    expect(alert).toHaveTextContent('Varian memakai biaya BOM.');
    expect(alert).toHaveTextContent('KOP-2');
    expect(view.emitted().saved).toBeUndefined();
  }, 30000);

  it('a failed load is an error with Retry, never an empty form', async () => {
    const user = await userEv();
    listMaterials.mockRejectedValueOnce(new Error('boom'));
    const pinia = createPinia();
    setActivePinia(pinia);
    render(RecipeDrawer, { props: { open: true, product: PRODUCT, versions: VERSIONS }, global: { plugins: [pinia] } });
    expect(await screen.findByText(/data resep gagal dimuat/i)).toBeInTheDocument();
    expect(screen.queryByTestId('rc-versioning')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByTestId('rc-versioning')).toBeInTheDocument();
    expect(lineRows()).toHaveLength(3);
  }, 30000);
});
