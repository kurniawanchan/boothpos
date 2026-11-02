/**
 * 088 — Laporan Bahan: status (urutan risiko sama dengan server, MaterialReport::STATUS_RANK), nada pill, teks hari
 * tersisa, dan preferensi tampilan PER PENGGUNA (jendela acuan, ambang "akan habis", target hari persediaan) di
 * localStorage — preferensi penonton, bukan data bisnis (research D10). Setiap akses penyimpanan dibungkus try/catch.
 */
export const STATUSES = ['negative', 'out', 'low', 'ok', 'unused'];

const TONES = { negative: 'danger', out: 'danger', low: 'warning', ok: 'success', unused: 'neutral' };
export const statusTone = (status) => TONES[status] ?? 'neutral';

const LIMITS = { window_days: [1, 90], risk_days: [1, 60], target_days: [1, 90] };
export const DEFAULT_PREFS = Object.freeze({ window_days: 7, risk_days: 3, target_days: 7 });

const fmtDays = (d) => Number(d).toLocaleString('id-ID', { maximumFractionDigits: 1 });

/** "tidak terpakai" / "pemakaian tidak tercatat" / "habis sekarang" / "±1,5 hari". */
export function daysLeftText(row, t) {
  if (row.days_left === null || row.days_left === undefined) return t(row.usage_untracked ? 'reports.mat_days_untracked' : 'reports.mat_days_unused');
  if (Number(row.days_left) <= 0) return t('reports.mat_days_none');
  return t('reports.mat_days_left', { days: fmtDays(row.days_left) });
}

const key = (userId) => `materialReport.prefs.${userId ?? 'anon'}`;

export function loadPrefs(userId) {
  try {
    const raw = JSON.parse(localStorage.getItem(key(userId)) ?? 'null');
    if (!raw || typeof raw !== 'object') return { ...DEFAULT_PREFS };
    const ok = Object.entries(LIMITS).every(([k, [min, max]]) => Number.isInteger(raw[k]) && raw[k] >= min && raw[k] <= max);
    return ok ? { window_days: raw.window_days, risk_days: raw.risk_days, target_days: raw.target_days } : { ...DEFAULT_PREFS };
  } catch {
    return { ...DEFAULT_PREFS };
  }
}

export function savePrefs(userId, prefs) {
  try {
    localStorage.setItem(key(userId), JSON.stringify(prefs));
  } catch {
    // Mode privat / penyimpanan diblokir: preferensi hanya berlaku sampai halaman ditutup.
  }
}
