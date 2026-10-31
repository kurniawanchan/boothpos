import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import id from '../../resources/js/locales/id.json';
import { STOCK_SOURCES, stockText, variantHasOwnStock, stockSourceWarningKeys } from '../../resources/js/utils/stockSource';

// 087 US6 — tampilan sumber stok produk (server tetap satu-satunya penentu).
const { t, te } = createI18n({ legacy: false, locale: 'id', messages: { id } }).global;

describe('stockSource', () => {
  it('offers exactly variant and modifier', () => {
    expect(STOCK_SOURCES).toEqual(['variant', 'modifier']);
    for (const s of STOCK_SOURCES) {
      expect(te(`products.stock_source_${s}`)).toBe(true);
      expect(te(`products.stock_source_${s}_hint`)).toBe(true);
    }
  });

  it('shows a dash instead of a number for variants without their own stock', () => {
    expect(stockText({ current_stock: 12, track_stock: true, stock_tracked: false })).toBe('—');
    expect(stockText({ current_stock: 12, track_stock: true, stock_tracked: true })).toBe('12');
  });

  it('falls back to track_stock for payloads without stock_tracked (older responses, POS payload)', () => {
    expect(variantHasOwnStock({ track_stock: false })).toBe(false);
    expect(variantHasOwnStock({ track_stock: true })).toBe(true);
    expect(variantHasOwnStock({})).toBe(true);
  });

  it('maps every server warning code to an existing translation', () => {
    const keys = stockSourceWarningKeys({ warnings: [{ code: 'variant_stock_remaining' }, { code: 'no_material_mappings' }] });
    expect(keys.every((k) => te(k))).toBe(true);
    expect(t(keys[0])).toContain('saldo');
    expect(stockSourceWarningKeys({ id: 1 })).toEqual([]);
  });
});
