import { describe, it, expect } from 'vitest';
import { MATERIAL_UNITS, OTHER_UNIT, isStandardUnit, needsCustomUnit, unitOptions } from '../../resources/js/utils/materialUnits';

describe('materialUnits', () => {
  it('keeps the units already used by real data exactly as written (pcs, gram), not renamed', () => {
    expect(MATERIAL_UNITS).toContain('pcs');
    expect(MATERIAL_UNITS).toContain('gram');
    expect(MATERIAL_UNITS).not.toContain('gr');
  });

  it('covers count, weight, volume and length units a kitchen needs', () => {
    for (const u of ['butir', 'bungkus', 'kg', 'ml', 'liter', 'meter', 'porsi']) expect(MATERIAL_UNITS).toContain(u);
  });

  it('has no duplicates and every unit fits the 20 character server limit', () => {
    expect(new Set(MATERIAL_UNITS).size).toBe(MATERIAL_UNITS.length);
    expect(MATERIAL_UNITS.every((u) => u.length <= 20)).toBe(true);
  });

  it('offers every unit plus a final "other" choice with the given label', () => {
    const opts = unitOptions('Lainnya…');
    expect(opts).toHaveLength(MATERIAL_UNITS.length + 1);
    expect(opts.at(-1)).toEqual({ value: OTHER_UNIT, label: 'Lainnya…' });
    expect(opts[0]).toEqual({ value: 'pcs', label: 'pcs' });
  });

  it('the "other" sentinel can never collide with a real unit', () => {
    expect(isStandardUnit(OTHER_UNIT)).toBe(false);
  });

  it('only a non-empty unit outside the list needs the free-text mode', () => {
    expect(needsCustomUnit('ml')).toBe(false);
    expect(needsCustomUnit('')).toBe(false);
    expect(needsCustomUnit(null)).toBe(false);
    expect(needsCustomUnit(undefined)).toBe(false);
    expect(needsCustomUnit('lusin')).toBe(true);
    expect(needsCustomUnit('  ml  ')).toBe(false);
  });
});
