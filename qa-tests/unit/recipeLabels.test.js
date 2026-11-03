import { describe, it, expect } from 'vitest';
import en from '../../resources/js/locales/en.json';
import id from '../../resources/js/locales/id.json';

// 089 US2 — label "Yield" menjadi "Portions" (Indonesia: "Porsi"), tanpa satuan di samping isian, dan teks layar Resep
// tidak lagi berbicara tentang versi atau menyatakan stok tidak dipotong.
const rc = (locale) => Object.fromEntries(Object.entries(locale.products ?? locale).filter(([k]) => k.startsWith('rc_')));

describe('recipe screen wording (089)', () => {
  it('uses "Portions" / "Porsi" for the yield label and error', () => {
    expect(en.products.rc_yield).toBe('Portions');
    expect(id.products.rc_yield).toBe('Porsi');
    expect(en.products.rc_err_yield).toMatch(/^Portions /);
    expect(id.products.rc_err_yield).toMatch(/^Porsi /);
  });

  it('no recipe-screen string still says "yield" or "hasil (yield)"', () => {
    for (const [lang, strings] of [['en', rc(en)], ['id', rc(id)]]) {
      for (const [key, value] of Object.entries(strings)) {
        expect(value, `${lang}.${key}`).not.toMatch(/yield/i);
      }
    }
  });

  it('has no unit string beside the portions field', () => {
    expect(en.products.rc_yield_unit).toBeUndefined();
    expect(id.products.rc_yield_unit).toBeUndefined();
  });

  it('the screen note says stock is deducted when an order completes and no longer mentions new versions or "does not deduct"', () => {
    expect(en.products.rc_stock_note).toMatch(/deducted when an order is completed/i);
    expect(id.products.rc_stock_note).toMatch(/dipotong saat pesanan selesai/i);
    for (const strings of [rc(en), rc(id)]) {
      for (const value of Object.values(strings)) {
        expect(value).not.toMatch(/NEW VERSION|VERSI BARU|does not deduct|tidak mengurangi stok/i);
      }
    }
    expect(en.products.rc_versioning).toBeUndefined();
    expect(id.products.rc_versioning).toBeUndefined();
  });

  it('keeps en and id in step for every recipe-screen key', () => {
    expect(Object.keys(rc(id)).sort()).toEqual(Object.keys(rc(en)).sort());
  });
});
