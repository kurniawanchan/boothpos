import { describe, it, expect, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import id from '../../resources/js/locales/id.json';
import { materialWarningMessages, toastMaterialWarnings } from '../../resources/js/utils/materialWarnings';

// 087 — peringatan bahan menjadi minus dari respons penyelesaian pesanan.
const { t } = createI18n({ legacy: false, locale: 'id', messages: { id } }).global;
const WARN = { material_id: 7, material: 'Telur', unit: 'butir', outlet_id: 1, stock_before: '1.000', required: '2.000', stock_after: '-1.000' };

describe('materialWarnings', () => {
  it('formats one message per warning with the material, unit and the new negative balance', () => {
    const [msg] = materialWarningMessages({ material_warnings: [WARN] }, t);
    expect(msg).toContain('Telur');
    expect(msg).toContain('stok 1 butir');
    expect(msg).toContain('dibutuhkan 2');
    expect(msg).toContain('kini −1');
  });

  it('names the outlet, because material stock is per outlet (2026-10-31)', () => {
    const [msg] = materialWarningMessages({ material_warnings: [{ ...WARN, outlet: 'Warkop WJM Demo' }] }, t);
    expect(msg).toMatch(/^Telur di Warkop WJM Demo: stok 1 butir/);
  });

  it('keeps fractional quantities readable', () => {
    const [msg] = materialWarningMessages({ material_warnings: [{ ...WARN, unit: 'gram', stock_before: '0.500', required: '1.250', stock_after: '-0.750' }] }, t);
    expect(msg).toContain('dibutuhkan 1,25');
    expect(msg).toContain('kini −0,75');
  });

  it('shows no toast when the response has no warnings (the usual case)', () => {
    const toast = { warning: vi.fn() };
    toastMaterialWarnings({ id: 1, status: 'completed' }, t, toast);
    toastMaterialWarnings(undefined, t, toast);
    expect(toast.warning).not.toHaveBeenCalled();
  });

  it('shows each warning as a warning toast, never as an error', () => {
    const toast = { warning: vi.fn(), error: vi.fn() };
    toastMaterialWarnings({ material_warnings: [WARN, { ...WARN, material: 'Cabai Bubuk' }] }, t, toast);
    expect(toast.warning).toHaveBeenCalledTimes(2);
    expect(toast.error).not.toHaveBeenCalled();
  });
});
