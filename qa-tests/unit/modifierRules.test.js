import { describe, it, expect } from 'vitest';
import { groupRange, describeRange, isSelectionValid, selectionDelta, selectedOptionIds, materialPortions, portionCount } from '../../resources/js/utils/modifierRules';

// Harus sejalan dengan ModifierResolver::range() di server (tests/Feature/ModifierResolverTest).
const single = (over) => ({ id: 1, selection_type: 'single', is_required: true, min_select: 1, max_select: 1, options: [], ...over });
const multi = (over) => ({ id: 2, selection_type: 'multiple', is_required: false, min_select: 0, max_select: 2, options: [], ...over });

describe('groupRange', () => {
  it('single choice: exactly one when required, zero-or-one when optional', () => {
    expect(groupRange(single())).toEqual([1, 1]);
    expect(groupRange(single({ is_required: false }))).toEqual([0, 1]);
  });

  it('single choice ignores whatever min/max the payload carries', () => {
    expect(groupRange(single({ min_select: 5, max_select: 9 }))).toEqual([1, 1]);
  });

  it('multiple choice: a required group needs at least one, even when min is 0', () => {
    expect(groupRange(multi({ is_required: true, min_select: 0 }))).toEqual([1, 2]);
    expect(groupRange(multi({ is_required: false, min_select: 0 }))).toEqual([0, 2]);
  });

  it('multiple choice with no upper bound', () => {
    expect(groupRange(multi({ max_select: null }))).toEqual([0, null]);
  });
});

describe('describeRange', () => {
  it('matches the wording the server uses in its 422 message', () => {
    expect(describeRange([1, 1])).toBe('1');
    expect(describeRange([1, 2])).toBe('1–2');
    expect(describeRange([0, null])).toBe('≥0');
    expect(describeRange([2, null])).toBe('≥2');
  });
});

describe('isSelectionValid', () => {
  const groups = [single({ id: 1 }), multi({ id: 2, max_select: 2 })];

  it('is invalid until every required group has its choice', () => {
    expect(isSelectionValid(groups, { 1: [], 2: [] })).toBe(false);
    expect(isSelectionValid(groups, { 1: [10], 2: [] })).toBe(true);
  });

  it('rejects too many choices in a bounded group', () => {
    expect(isSelectionValid(groups, { 1: [10], 2: [20, 21, 22] })).toBe(false);
    expect(isSelectionValid(groups, { 1: [10], 2: [20, 21] })).toBe(true);
  });

  it('treats a group with no entry as an empty selection', () => {
    expect(isSelectionValid([multi()], {})).toBe(true);
    expect(isSelectionValid([single()], {})).toBe(false);
  });
});

describe('selectionDelta and selectedOptionIds', () => {
  const groups = [
    single({ id: 1, options: [{ id: 10, price_delta: '0.00' }, { id: 11, price_delta: '5000.00' }] }),
    multi({ id: 2, options: [{ id: 20, price_delta: '8000.00' }, { id: 21, price_delta: '-2000.00' }] }),
  ];

  it('sums signed differences of the chosen options only', () => {
    expect(selectionDelta(groups, { 1: [11], 2: [20, 21] })).toBe(11000);
    expect(selectionDelta(groups, { 1: [10], 2: [] })).toBe(0);
  });

  it('flattens the chosen ids across groups', () => {
    expect(selectedOptionIds({ 1: [11], 2: [20, 21] })).toEqual([11, 20, 21]);
  });
});

// 2026-10-31 (permintaan pemilik, lanjutan 087) — "tiap pilihan = 1 porsi" dan stok bahan per pilihan.
describe('portion groups and material stock', () => {
  const rasa = {
    id: 1, name: 'Rasa', selection_type: 'multiple', is_required: true, min_select: 1, max_select: 2, portion_per_option: true,
    options: [
      { id: 11, name: 'SOTO', price_delta: '0.00', portions: 17, materials: [{ material_id: 9, name: 'Indomie Soto', unit: 'pcs', quantity: '1.0000', on_hand: '17.000' }] },
      { id: 12, name: 'AYAM BAWANG', price_delta: '0.00', portions: 0, materials: [{ material_id: 10, name: 'Indomie Ayam Bawang', unit: 'pcs', quantity: '1.0000', on_hand: '-1.000' }] },
    ],
  };
  const topping = {
    id: 2, name: 'Topping', selection_type: 'multiple', is_required: false, min_select: 0, max_select: null, portion_per_option: false,
    options: [{ id: 21, name: 'Telur', price_delta: '3000.00', portions: 4, materials: [] }],
  };

  it('charges one more base price for each extra option of a portion group, in option order like the server', () => {
    expect(selectionDelta([rasa, topping], { 1: [11], 2: [] }, 8000)).toBe(0);
    expect(selectionDelta([rasa, topping], { 1: [12, 11], 2: [21] }, 8000)).toBe(8000 + 3000);
    expect(selectionDelta([rasa], { 1: [11, 12] })).toBe(0); // tanpa harga dasar: perilaku lama
  });

  it('labels an extra portion with the base price', () => {
    expect(portionCount([rasa], { 1: [11, 12] })).toBe(2);
    expect(portionCount([rasa, topping], { 1: [11], 2: [21] })).toBe(1);
  });

  it('sums the portions of a required material group and takes the smallest group', () => {
    expect(materialPortions([rasa, topping])).toBe(17);
    const kuah = { ...rasa, id: 3, options: [{ id: 31, name: 'Kuah', portions: 5, materials: [{}] }] };
    expect(materialPortions([rasa, kuah])).toBe(5);
  });

  it('is unknown (null) when a required group has an option without materials or nothing tracks materials', () => {
    const plain = { ...rasa, options: [...rasa.options, { id: 13, name: 'Tanpa bahan', portions: null, materials: [] }] };
    expect(materialPortions([plain])).toBeNull();
    expect(materialPortions([topping])).toBeNull();
    expect(materialPortions([])).toBeNull();
  });
});
