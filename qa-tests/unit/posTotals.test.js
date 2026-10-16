import { describe, it, expect } from 'vitest';
import { cartTotals, taxOnBase } from '../../resources/js/utils/posTotals';

/**
 * Paritas dengan server: contoh di sini sama dengan tests/Feature/RetailTaxReportingTest.php
 * (App\Support\TaxCalculator + OrderService). Jika salah satu berubah, ubah keduanya.
 */
describe('POS cart totals — parity with the server tax rule', () => {
  it('60.000 at 10% gives 6.000 tax and 66.000 total, the same as the server', () => {
    expect(cartTotals({ subtotal: '60000.00', discount: 0, taxRate: 10 })).toEqual({
      subtotal: 60000,
      discount: 0,
      base: 60000,
      tax: 6000,
      total: 66000,
    });
  });

  it('rounds the tax half-up per order to whole rupiah, as PHP round(…, 0, HALF_UP) does (100.5 → 101)', () => {
    expect(taxOnBase(1005, 10)).toBe(101);
  });

  it('keeps fractional rates exact (11.5% of 1.000 = 115)', () => {
    expect(taxOnBase(1000, 11.5)).toBe(115);
  });

  it('adds no tax when the rate is 0, so booth installs without tax are unchanged', () => {
    expect(cartTotals({ subtotal: '60000.00', discount: 0, taxRate: 0 }).total).toBe(60000);
  });
});

describe('POS cart totals — order-level discount', () => {
  it('60.000 − 10.000 discount at 10%: tax on 50.000 is 5.000 and the total is 55.000', () => {
    expect(cartTotals({ subtotal: '60000.00', discount: 10000, taxRate: 10 })).toEqual({
      subtotal: 60000,
      discount: 10000,
      base: 50000,
      tax: 5000,
      total: 55000,
    });
  });

  it('clamps the taxable base at zero when the discount is larger than the subtotal', () => {
    expect(cartTotals({ subtotal: '60000.00', discount: 70000, taxRate: 10 })).toMatchObject({
      base: 0,
      tax: 0,
      total: 0,
    });
  });
});
