import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import DashboardView from '../../resources/js/views/DashboardView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { salesReport, profitReport, outletSettlements } from '../../resources/js/api/reports';
import { lowStock } from '../../resources/js/api/stock';
import { summary, attention, orders } from '../../resources/js/api/dashboard';

vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), profitReport: vi.fn(), outletSettlements: vi.fn() }));
vi.mock('../../resources/js/api/stock', () => ({ lowStock: vi.fn(), listMovements: vi.fn() }));
vi.mock('../../resources/js/api/dashboard', () => ({ summary: vi.fn(), attention: vi.fn(), orders: vi.fn(), downloadOrdersCsv: vi.fn(), exportDashboard: vi.fn(),
  // 065 — panel "Shift terbuka": kosong di tes ini (diuji sendiri di OpenShiftsPanel.test.js).
  shifts: vi.fn().mockResolvedValue({ data: [], total_open: 0 }) }));
vi.mock('vue-chartjs', () => ({
  Bar: { template: '<div data-testid="bar-chart" />' },
  Doughnut: { template: '<div data-testid="doughnut-chart" />' },
}));

const ROUTE_NAMES = ['dashboard', 'pos', 'preorders', 'stock', 'products', 'reports', 'events'];

async function renderDashboard(menuKeys) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'Test', username: 'test', role: 'Owner', menu_keys: menuKeys };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ROUTE_NAMES.map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  router.push('/dashboard');
  await router.isReady();
  return render(DashboardView, { global: { plugins: [pinia, router] } });
}

// 042-dashboard-revamp (T025) — Dashboard baru membungkus panel LAMA di bagian "Rincian periode" yang dimuat malas (046).
// Tes ini membuktikan dari tampilan utuh bahwa semua isi lama (005 US2) tetap terjangkau dan berperilaku sama;
// rincian per-panel ada di PeriodSection.test.js.
describe('DashboardView — everything the old Dashboard showed is still reachable (042)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    summary.mockResolvedValue({ computed_at: '2026-10-11T12:30:04+07:00', business_date: '2026-10-11', comparison: { basis: 'same_time_yesterday', up_to: '12:30' }, kpis: {} });
    attention.mockResolvedValue({ computed_at: '2026-10-11T12:30:04+07:00', count: 0, items: [] });
    orders.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, counts: { all: 0, open: 0, partial: 0, paid: 0, cancelled: 0 }, hidden_tabs: ['bill', 'closed'] });
    salesReport.mockResolvedValue({ totals: { net_sales: '0.00', order_count: 0, unit_count: 0, discount_total: '0.00' }, rows: [] });
    lowStock.mockResolvedValue({ data: [] });
    profitReport.mockResolvedValue({ gross_profit: '0.00' });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('shows only the shortcuts the current role has menu access to', async () => {
    await renderDashboard(['dashboard', 'pos']);
    expect(await screen.findByText('Transaksi Baru', {}, { timeout: 10000 })).toBeInTheDocument();
    expect(screen.queryByText('Tambah Produk')).not.toBeInTheDocument();
    expect(screen.queryByText('Sesuaikan Stok')).not.toBeInTheDocument();
  }, 30000);

  it('re-fetches the sales panel with the chosen date range', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderDashboard(['dashboard']);
    await screen.findByText('Penjualan per kategori');
    await waitFor(() => expect(salesReport).toHaveBeenCalled());
    salesReport.mockClear();

    // 046: kotak tanggal "Rincian periode" terisi hari ini; ganti ke tanggal lain, laporan dimuat ulang dengan rentang itu.
    const [fromInput] = within(screen.getByTestId('per-period-section')).getAllByDisplayValue(/^\d{4}-\d{2}-\d{2}$/);
    await user.clear(fromInput);
    await user.type(fromInput, '2026-09-01');
    await waitFor(() =>
      expect(salesReport).toHaveBeenCalledWith(expect.objectContaining({ group_by: 'day', date_from: '2026-09-01' }))
    );
  });

  it('offers the charts, drill-through links and stock panel under a "Rincian periode" heading', async () => {
    await renderDashboard(['dashboard']);
    expect(await screen.findByRole('heading', { level: 2, name: 'Rincian periode' })).toBeInTheDocument();
    expect(await screen.findByText('Penjualan per kategori')).toBeInTheDocument();
    expect(screen.getByText('Penjualan per outlet')).toBeInTheDocument();
    expect(screen.queryByText('Penjualan per event')).not.toBeInTheDocument();
    expect(screen.getAllByText(/lihat laporan lengkap/i).length).toBeGreaterThan(0);
    expect(screen.getByText(/lihat pergerakan stok/i)).toBeInTheDocument();
    expect(screen.queryByText(/lihat semua pre-order/i)).not.toBeInTheDocument();
  });

  it('keeps the reports-only panels behind the reports menu (profit KPI, customer statistics, per-outlet)', async () => {
    await renderDashboard(['dashboard']);
    await screen.findByText('Penjualan per kategori');
    expect(profitReport).not.toHaveBeenCalled();
    expect(screen.queryByText('Statistik per Pelanggan')).not.toBeInTheDocument();
    expect(screen.queryByText('Hasil per outlet')).not.toBeInTheDocument();
  });
});
