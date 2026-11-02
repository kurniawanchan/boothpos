import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listOutlets } from '../../resources/js/api/outlets';
import { getMaterialPosition, outletSettlements, exportReport } from '../../resources/js/api/reports';
import userEvent from '@testing-library/user-event';

vi.mock('vue-router', () => ({ useRoute: () => ({ query: globalThis.__q ?? {} }), useRouter: () => ({ push: vi.fn() }) }));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  outletSettlements: vi.fn(), outletSettlementTransactions: vi.fn(), profitReport: vi.fn(), outletProfitReport: vi.fn(),
  purchasesReport: vi.fn(), stockByOutletReport: vi.fn(), exportReport: vi.fn(), getMaterialPosition: vi.fn(), createMaterialPurchaseDrafts: vi.fn(),
}));

function renderReports(query) {
  globalThis.__q = query;
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'reports'] };
  return render(ReportsView, { global: { plugins: [pinia] } });
}

// 088 US3 (T029) — kartu Dashboard membuka Laporan dengan `?tab=materials&status=…`; tab tak dikenal → bawaan.
describe('ReportsView — opening tab from the link', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: [] });
    outletSettlements.mockResolvedValue({ data: [] });
    getMaterialPosition.mockResolvedValue({ data: [], summary: { negative: 0, out: 0, low: 0, ok: 0, unused: 0, at_risk_products: 0, stock_value: null, suggestion_cost: '0.00' }, params: { window_days: 7, risk_days: 3, target_days: 7 } });
  });

  it('opens the Materials tab with the status from the link', async () => {
    renderReports({ tab: 'materials', status: 'low' });
    expect(await screen.findByTestId('materials-tab')).toBeInTheDocument();
    expect(getMaterialPosition).toHaveBeenCalledWith(expect.objectContaining({ status: ['low'] }));
    expect(outletSettlements).not.toHaveBeenCalled();
  });

  it('ignores an unknown tab', async () => {
    renderReports({ tab: 'nope' });
    await screen.findByRole('tablist');
    expect(screen.queryByTestId('materials-tab')).toBeNull();
  });

  // 088 US5 (T040) — tombol ekspor kepala halaman mengekspor tab Bahan DENGAN saringannya.
  it('exports the Materials tab with its active parameters and filters', async () => {
    exportReport.mockResolvedValue();
    localStorage.setItem('materialReport.prefs.1', JSON.stringify({ window_days: 14, risk_days: 2, target_days: 10 }));
    renderReports({ tab: 'materials', status: 'low' });
    await screen.findByTestId('materials-tab');
    await userEvent.setup().click(screen.getByRole('button', { name: /ekspor \.xlsx/i }));
    expect(exportReport).toHaveBeenCalledWith('materials', expect.objectContaining({ window_days: 14, risk_days: 2, target_days: 10, status: ['low'] }));
  });
});
