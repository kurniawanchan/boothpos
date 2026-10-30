import { describe, it, expect } from 'vitest';
import { cartTotals, taxOnBase } from '../../resources/js/utils/posTotals';
import fixture from '../fixtures/tax-parity-cases.json';

/**
 * 085 / US1 — paritas pajak. Kasus dibaca dari fixture yang SAMA dengan tests/Feature/TaxParityTest.php dan tes
 * Android, supaya Web, server, dan tablet tidak bisa menyimpang diam-diam. Dulu semua tes pajak memakai 10%, jadi tarif
 * yang macet di 10 tidak akan tertangkap.
 */
describe('taxOnBase — shared parity fixture', () => {
  it.each(fixture.cases.map((c) => [c.base, c.rate, c.tax, c.note]))(
    'base %i at %f%% gives tax %i (%s)',
    (base, rate, tax) => {
      expect(taxOnBase(base, rate)).toBe(tax);
    },
  );
});

describe('cartTotals — a rate other than 10 is the rate that is used', () => {
  it('100% on 17.000 doubles the total', () => {
    expect(cartTotals({ subtotal: '17000.00', discount: 0, taxRate: 100 })).toEqual({
      subtotal: 17000, discount: 0, base: 17000, tax: 17000, total: 34000,
    });
  });

  it('accepts the rate as a numeric string, as the settings endpoint may return it', () => {
    expect(cartTotals({ subtotal: 60000, discount: 0, taxRate: '5.00' }).tax).toBe(3000);
  });

  it('takes the order discount off before the tax (60.000 − 10.000 at 5% = 2.500)', () => {
    expect(cartTotals({ subtotal: 60000, discount: 10000, taxRate: 5 })).toMatchObject({ base: 50000, tax: 2500, total: 52500 });
  });

  it('never goes negative when the discount exceeds the subtotal', () => {
    expect(cartTotals({ subtotal: 1000, discount: 5000, taxRate: 100 })).toMatchObject({ base: 0, tax: 0, total: 0 });
  });

  it('a rate of 0 means no tax', () => {
    expect(cartTotals({ subtotal: 12345, discount: 0, taxRate: 0 }).tax).toBe(0);
  });
});
