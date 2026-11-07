import { describe, it, expect } from 'vitest';
import { hasRecipeStock } from '../../resources/js/utils/posProductCards';

// 092 D8 — POS dan Tab memuat ulang daftar menu setelah transaksi HANYA bila ada menu beresep (stok menu dihitung
// server dan berubah tiap penjualan/baris tab). Tanpa menu beresep tidak ada permintaan tambahan (perilaku lama).
// Catatan: tes ini mengunci predikatnya; pemuatan ulang sungguhan setelah bayar diverifikasi di peramban (T042).
describe('hasRecipeStock', () => {
  const p = (basis) => ({ id: 1, variants: [{ id: 1, stock_basis: basis }] });

  it('is true when any variant of any product is recipe-based', () => {
    expect(hasRecipeStock([p('variant'), p('recipe')])).toBe(true);
  });

  it('is false for a menu with no recipe variants, and for old payloads without the field', () => {
    expect(hasRecipeStock([p('variant')])).toBe(false);
    expect(hasRecipeStock([{ id: 1, variants: [{ id: 1 }] }])).toBe(false);
  });

  it('is safe on empty, missing and variant-less input', () => {
    expect(hasRecipeStock([])).toBe(false);
    expect(hasRecipeStock(undefined)).toBe(false);
    expect(hasRecipeStock([{ id: 1 }])).toBe(false);
  });
});
