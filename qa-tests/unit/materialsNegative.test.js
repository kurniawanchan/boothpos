import { describe, it, expect } from 'vitest';
import { isNegativeStock, materialStockText, negativeOutlets } from '../../resources/js/utils/materialStock';

// 087 — penanda saldo bahan minus di daftar bahan.
describe('materialStock', () => {
  it('flags a material when any visible outlet balance is below zero', () => {
    const row = { current_stock: '9.000', outlet_stock: [{ outlet_code: 'WJA', on_hand: '-1.000' }, { outlet_code: 'WJB', on_hand: '10.000' }] };
    expect(isNegativeStock(row)).toBe(true);
    expect(negativeOutlets(row).map((o) => o.outlet_code)).toEqual(['WJA']);
  });

  it('does not flag zero or positive balances', () => {
    expect(isNegativeStock({ current_stock: '0.000', outlet_stock: [{ on_hand: '0.000' }] })).toBe(false);
    expect(isNegativeStock({ current_stock: '3.000', outlet_stock: [] })).toBe(false);
  });

  it('falls back to the total when there is no per-outlet breakdown', () => {
    expect(isNegativeStock({ current_stock: '-0.500' })).toBe(true);
  });
});

// 2026-10-31 (laporan pemilik): saldo outlet terpilih yang belum punya baris dulu jatuh ke TOTAL semua outlet,
// sehingga "15" (milik WJ3) tampil saat outlet WDM dipilih dan penjualan di WDM membuat −1.
describe('materialStockText', () => {
  const row = { current_stock: '15.000', outlet_stock: [{ outlet_id: 4, outlet_code: 'WJ3', on_hand: '15.000' }] };

  it('shows the selected outlet balance, zero when it has no row (never the total)', () => {
    expect(materialStockText(row, { multi: true, selected: 14 })).toBe('0');
    expect(materialStockText(row, { multi: true, selected: 4 })).toBe('15');
  });

  it('lists every outlet with its code when all outlets are visible', () => {
    const two = { current_stock: '14.000', outlet_stock: [{ outlet_id: 4, outlet_code: 'WJ3', on_hand: '15.000' }, { outlet_id: 14, outlet_code: 'WDM', on_hand: '-1.000' }] };
    expect(materialStockText(two, { multi: true, selected: null })).toBe('WJ3 15 · WDM −1');
    expect(materialStockText({ current_stock: '0.000', outlet_stock: [] }, { multi: true, selected: null })).toBe('0');
  });

  it('uses the total with a single outlet', () => {
    expect(materialStockText(row, { multi: false, selected: null })).toBe('15');
  });

  it('judges negativity on the selected outlet only', () => {
    const two = { current_stock: '14.000', outlet_stock: [{ outlet_id: 4, on_hand: '15.000' }, { outlet_id: 14, on_hand: '-1.000' }] };
    expect(isNegativeStock(two, 4)).toBe(false);
    expect(isNegativeStock(two, 14)).toBe(true);
  });
});
