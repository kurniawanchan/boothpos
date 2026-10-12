import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import DashboardView from '../../resources/js/views/DashboardView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { salesReport, profitReport, outletSettlements } from '../../resources/js/api/reports';
import { lowStock } from '../../resources/js/api/stock';
import { summary } from '../../resources/js/api/dashboard';

vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), profitReport: vi.fn(), outletSettlements: vi.fn() }));
vi.mock('../../resources/js/api/stock', () => ({ lowStock: vi.fn() }));
vi.mock('../../resources/js/api/dashboard', () => ({ summary: vi.fn(), exportDashboard: vi.fn() }));
vi.mock('vue-chartjs', () => ({
  Bar: { template: '<div />' },
  Doughnut: { template: '<div />' },
}));

const NAMES = ['dashboard', 'pos', 'preorders', 'stock', 'products', 'reports', 'events'];

async function renderDashboard(menuKeys) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'T', username: 't', role: 'Owner', menu_keys: menuKeys };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: NAMES.map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  router.push('/dashboard');
  await router.isReady();
  return render(DashboardView, { global: { plugins: [pinia, router] } });
}

const SUMMARY = {
  computed_at: '2026-10-11T12:30:04+07:00',
  business_date: '2026-10-11',
  period: 'today',
  comparison: { basis: 'same_time_yesterday', up_to: '12:30' },
  kpis: {
    net_sales: { value: '18420500.00', comparison: '17247000.00', change_pct: 6.8, series: [], comparison_series: [] },
    orders: { value: 486, comparison: 466, change_pct: 4.3, series: [], comparison_series: [] },
    avg_order_value: { value: '37902.00', comparison: '37020.00', change_pct: 2.4, series: [], comparison_series: [] },
    low_stock: { value: 7, comparison: 4, change_abs: 3, scope: 'organisation', series: [], comparison_series: [] },
  },
};

// 041 (US2) menempatkan Dashboard di page kit; 042 menggantinya dengan struktur baru: PageHeader + wilayah angka
// utama (dari /dashboard/summary) + tempat panel berikutnya + bagian "Rincian periode" (046: dulu
// "Per event"; strip empat angka LAMA hidup di dalamnya dan diuji di PeriodSection.test.js).
describe('DashboardView — page structure (042)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    summary.mockResolvedValue(SUMMARY);
    salesReport.mockResolvedValue({ totals: { net_sales: '1250000.00', order_count: 7, unit_count: 19, discount_total: '5000.00' }, rows: [] });
    lowStock.mockResolvedValue({ data: [] });
    profitReport.mockResolvedValue({ gross_profit: '400000.00' });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('has ONE page heading (h1) and the period breakdown section as an h2', async () => {
    await renderDashboard(['dashboard', 'reports']);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Beranda'); // nav.dashboard (id)
    expect(await screen.findByRole('heading', { level: 2, name: 'Rincian periode' }, { timeout: 10000 })).toBeInTheDocument();
  }, 30000);

  it('shows exactly four KPI cells whose values come from the summary endpoint', async () => {
    await renderDashboard(['dashboard']);
    const region = await screen.findByTestId('dashboard-kpis');
    await within(region).findByText('Rp 18.420.500');
    expect(region.querySelectorAll('[data-testid^="kpi-"][data-testid$="_sales"], [data-testid="kpi-orders"], [data-testid="kpi-avg_order_value"], [data-testid="kpi-low_stock"]')).toHaveLength(4);
    expect(within(region).getByText('486')).toBeInTheDocument();
    expect(within(region).getByText('Rp 37.902')).toBeInTheDocument();
    expect(within(region).getByText('7')).toBeInTheDocument();
  });

  it('shows the attention panel and the latest-orders table (the slots of the first iteration are now real regions)', async () => {
    await renderDashboard(['dashboard']);
    expect(await screen.findByTestId('attention-panel')).toBeInTheDocument();
    // Tabel dan "Rincian periode" menunggu ringkasan selesai dan komponen async dimuat; pada mesin yang sibuk itu
    // bisa melewati batas 1 detik bawaan — batas dilonggarkan, bukan perilakunya.
    expect(await screen.findByTestId('dashboard-orders', {}, { timeout: 10000 })).toBeInTheDocument();
  }, 30000);

  it('the old KPI strip (incl. gross profit for reports users) still renders inside "Rincian periode"', async () => {
    await renderDashboard(['dashboard', 'reports']);
    const section = await screen.findByTestId('per-period-section');
    expect(await within(section).findByText('Rp 1.250.000')).toBeInTheDocument();
    expect(await within(section).findByText('Rp 400.000')).toBeInTheDocument();
    await waitFor(() => expect(profitReport).toHaveBeenCalled());
  });

  it('calls the summary endpoint exactly once on load, plus each period panel call once', async () => {
    await renderDashboard(['dashboard', 'reports']);
    await screen.findByText('Rp 1.250.000');
    expect(summary).toHaveBeenCalledTimes(1);
    expect(lowStock).toHaveBeenCalledTimes(1);
  });
});
