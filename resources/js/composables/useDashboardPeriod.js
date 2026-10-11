import { computed } from 'vue';
import { useQueryState } from './useQueryState';

export const DASHBOARD_PERIODS = ['today', '7d', '30d'];
export const DEFAULT_PERIOD = 'today';

/**
 * 042-dashboard-revamp — periode Dashboard (hari ini / 7 hari / 30 hari) disalin ke address bar (?period=) supaya
 * reload dan tautan yang dibagikan memulihkan tampilan yang sama (FR-003). Nilai yang tak dikenal jatuh ke
 * bawaan alih-alih menembak API dengan period yang pasti ditolak 422 — server tetap yang memvalidasi, ini hanya
 * mencegah panggilan yang sia-sia. Bawaan ('today') tidak ditulis ke URL (perilaku useQueryState).
 */
export function useDashboardPeriod(deps) {
  const { state, set } = useQueryState({ period: DEFAULT_PERIOD }, deps);
  const period = computed(() => (DASHBOARD_PERIODS.includes(state.period) ? state.period : DEFAULT_PERIOD));

  function setPeriod(value) {
    return set({ period: DASHBOARD_PERIODS.includes(value) ? value : DEFAULT_PERIOD });
  }

  return { period, setPeriod };
}
