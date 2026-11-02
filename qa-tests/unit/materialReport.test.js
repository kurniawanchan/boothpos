import { describe, it, expect, beforeEach, vi } from 'vitest';
import { STATUSES, statusTone, daysLeftText, loadPrefs, savePrefs, DEFAULT_PREFS } from '../../resources/js/utils/materialReport';

const t = (k, p) => (p ? `${k}|${JSON.stringify(p)}` : k);

// 088 US1 (T009) — label/nada status, teks hari tersisa, preferensi per pengguna (localStorage, aman bila gagal).
describe('materialReport utils', () => {
  beforeEach(() => localStorage.clear());

  it('orders statuses by risk and gives each a tone', () => {
    expect(STATUSES).toEqual(['negative', 'out', 'low', 'ok', 'unused']);
    expect(statusTone('negative')).toBe('danger');
    expect(statusTone('out')).toBe('danger');
    expect(statusTone('low')).toBe('warning');
    expect(statusTone('ok')).toBe('success');
    expect(statusTone('unused')).toBe('neutral');
  });

  it('explains days left without misleading zero or infinity', () => {
    expect(daysLeftText({ status: 'unused', days_left: null, usage_untracked: false }, t)).toBe('reports.mat_days_unused');
    expect(daysLeftText({ status: 'unused', days_left: null, usage_untracked: true }, t)).toBe('reports.mat_days_untracked');
    expect(daysLeftText({ status: 'negative', days_left: 0 }, t)).toBe('reports.mat_days_none');
    expect(daysLeftText({ status: 'low', days_left: 1.5 }, t)).toBe('reports.mat_days_left|{"days":"1,5"}');
  });

  it('remembers preferences per user and falls back to defaults', () => {
    expect(loadPrefs(7)).toEqual(DEFAULT_PREFS);
    savePrefs(7, { window_days: 14, risk_days: 2, target_days: 10 });
    expect(loadPrefs(7)).toEqual({ window_days: 14, risk_days: 2, target_days: 10 });
    expect(loadPrefs(8)).toEqual(DEFAULT_PREFS);
  });

  it('survives storage that throws', () => {
    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
    expect(loadPrefs(7)).toEqual(DEFAULT_PREFS);
    spy.mockRestore();
    const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
    expect(() => savePrefs(7, DEFAULT_PREFS)).not.toThrow();
    set.mockRestore();
  });

  it('clamps garbage stored values to defaults', () => {
    localStorage.setItem('materialReport.prefs.7', '{"window_days":0,"risk_days":"x"}');
    expect(loadPrefs(7)).toEqual(DEFAULT_PREFS);
  });
});
