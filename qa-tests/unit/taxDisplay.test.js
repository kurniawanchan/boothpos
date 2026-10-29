import { describe, it, expect } from 'vitest';
import { shouldShowTaxLine, formatTaxLabel } from '../../resources/js/utils/taxDisplay';
import { cartTotals } from '../../resources/js/utils/posTotals';

// 083 — satu aturan tampil baris pajak untuk order tersimpan, dan toggle tidak pernah masuk rumus total.
describe('shouldShowTaxLine', () => {
  it('tampil bila pengaturan aktif dan order dikenai pajak', () => {
    expect(shouldShowTaxLine({ show: true, amount: '700.00' })).toBe(true);
  });

  it('tersembunyi bila pengaturan mati, berapa pun pajaknya', () => {
    expect(shouldShowTaxLine({ show: false, amount: '700.00' })).toBe(false);
  });

  it('order lama tanpa pajak tidak memunculkan baris "Pajak Rp 0"', () => {
    expect(shouldShowTaxLine({ show: true, amount: '0.00' })).toBe(false);
    expect(shouldShowTaxLine({ show: true, amount: null })).toBe(false);
    expect(shouldShowTaxLine({ show: true, amount: undefined })).toBe(false);
  });

  it('nilai pengaturan yang belum tiba (undefined) dianggap aktif seperti bawaan server', () => {
    expect(shouldShowTaxLine({ show: undefined, amount: '700.00' })).toBe(true);
  });
});

describe('formatTaxLabel', () => {
  it('tarif bulat tanpa desimal', () => {
    expect(formatTaxLabel('PPN', '10.00')).toBe('PPN (10%)');
    expect(formatTaxLabel('PPN', 10)).toBe('PPN (10%)');
  });

  it('tarif pecahan apa adanya', () => {
    expect(formatTaxLabel('PPN', '11.50')).toBe('PPN (11.5%)');
  });

  it('label kosong jatuh ke "Pajak"; tanpa tarif hanya label', () => {
    expect(formatTaxLabel('  ', '10')).toBe('Pajak (10%)');
    expect(formatTaxLabel('PPN', '0.00')).toBe('PPN');
    expect(formatTaxLabel(undefined, undefined)).toBe('Pajak');
  });
});

describe('cartTotals tidak mengenal toggle tampilan', () => {
  it('total memuat pajak 10% (7.000 → 7.700) tanpa parameter tampil', () => {
    const t = cartTotals({ subtotal: 7000, discount: 0, taxRate: 10 });
    expect(t.tax).toBe(700);
    expect(t.total).toBe(7700);
  });
});
