import { describe, it, expect } from 'vitest';
import { ingredientKey, isWholePerPortion, lineFromServer, linePayload, parseIngredientKey, productOptionLabel } from '../../resources/js/utils/recipeIngredients';

const t = (key, p = {}) => (key === 'products.rc_product_available' ? `tersedia ${p.qty} ${p.unit}` : key);

describe('recipeIngredients (093)', () => {
  it('encodes and decodes the ingredient key for both kinds', () => {
    expect(ingredientKey({ material_id: 12, variant_id: null })).toBe('m12');
    expect(ingredientKey({ material_id: null, variant_id: 34 })).toBe('v34');
    expect(ingredientKey({ material_id: null, variant_id: null })).toBe('');
    expect(parseIngredientKey('m12')).toEqual({ type: 'material', id: 12 });
    expect(parseIngredientKey('v34')).toEqual({ type: 'product', id: 34 });
    expect(parseIngredientKey('')).toBeNull();
    expect(parseIngredientKey('x9')).toBeNull();
  });

  it('labels a product option with name, sku and available stock; hides stock when unknown', () => {
    const p = { product_name: 'Telur', variant_name: 'Standard', sku: 'TLR-1', unit: 'pcs', available: 195 };
    expect(productOptionLabel(p, t)).toBe('Telur · TLR-1 · tersedia 195 pcs');
    expect(productOptionLabel({ ...p, available: null }, t)).toBe('Telur · TLR-1');
    expect(productOptionLabel({ ...p, variant_name: 'Besar' }, t)).toBe('Telur — Besar · TLR-1 · tersedia 195 pcs');
  });

  it('accepts only a whole number of at least one per portion', () => {
    expect(isWholePerPortion('1', '1')).toBe(true);
    expect(isWholePerPortion('4', '2')).toBe(true);
    expect(isWholePerPortion('1', '2')).toBe(false); // 0,5 per porsi
    expect(isWholePerPortion('1.5', '1')).toBe(false);
    expect(isWholePerPortion('', '1')).toBe(false);
    expect(isWholePerPortion('1', '0')).toBe(false);
  });

  it('maps a product line from the server with no shrinkage and keeps the flagged state', () => {
    let n = 0;
    const next = () => `l${++n}`;
    const row = lineFromServer({ material_id: null, variant_id: 7, name: 'Telur', unit: 'pcs', sku: 'TLR-1', kind: 'product', ingredient_flagged: false, quantity: '2.0000', shrinkage_pct: '0.00' }, next);
    expect(row).toMatchObject({ material_id: null, variant_id: 7, flagged: false, quantity: '2', shrinkage: '0', sku: 'TLR-1' });

    const mat = lineFromServer({ material_id: 3, name: 'Susu', unit: 'liter', quantity: '0.5000', shrinkage_pct: '20.00' }, next);
    expect(mat).toMatchObject({ material_id: 3, variant_id: null, flagged: true, shrinkage: '20' });
  });

  it('builds a payload with exactly one of material_id / variant_id', () => {
    expect(linePayload({ material_id: 3, variant_id: null, quantity: '0.5', shrinkage: '20' })).toEqual({ material_id: 3, quantity: 0.5, shrinkage_pct: 20 });
    expect(linePayload({ material_id: null, variant_id: 7, quantity: '2', shrinkage: '15' })).toEqual({ variant_id: 7, quantity: 2, shrinkage_pct: 0 });
  });
});
