import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import DashboardView from '../../resources/js/views/DashboardView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { salesReport, profitReport, artistSettlements } from '../../resources/js/api/reports';
import { lowStock } from '../../resources/js/api/stock';
import { listPreorders } from '../../resources/js/api/preorders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), profitReport: vi.fn(), artistSettlements: vi.fn() }));
vi.mock('../../resources/js/api/stock', () => ({ lowStock: vi.fn() }));
vi.mock('../../resources/js/api/preorders', () => ({ listPreorders: vi.fn() }));
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

const SALES = { totals: { net_sales: '1250000.00', order_count: 7, unit_count: 19, discount_total: '5000.00' }, rows: [] };

// 041 (US2) — Dashboard: PageHeader + KpiStrip dengan angka yang SAMA dari data yang sama.
describe('DashboardView — page kit structure (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES);
    lowStock.mockResolvedValue({ data: [{ variant_id: 1, product_name: 'P', sku: 'S1', current_stock: 0, low_stock_alert: 3 }, { variant_id: 2, product_name: 'Q', sku: 'S2', current_stock: 2, low_stock_alert: 3 }] });
    listPreorders.mockResolvedValue({ data: [], meta: { total: 0 } });
    profitReport.mockResolvedValue({ gross_profit: '400000.00' });
    artistSettlements.mockResolvedValue({ data: [] });
  });

  it('has one page heading and exactly four KPI cells whose values match the loaded data', async () => {
    await renderDashboard(['dashboard', 'pos', 'reports']);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);

    await screen.findByText('Rp 1.250.000');
    const strip = document.querySelector('section.grid');
    // net sales, transactions, gross profit, low stock
    expect(strip.children).toHaveLength(4);
    expect(within(strip).getByText('Rp 1.250.000')).toBeInTheDocument();
    expect(within(strip).getByText('7')).toBeInTheDocument();
    expect(await within(strip).findByText('Rp 400.000')).toBeInTheDocument();
    // jumlah item stok menipis = panjang daftar yang sama dengan panel "Stok menipis"
    expect(within(strip).getByText('2')).toBeInTheDocument();
  });

  it('shows "—" (not 0) for a figure that could not be loaded', async () => {
    salesReport.mockRejectedValue(new Error('down'));
    lowStock.mockRejectedValue(new Error('down'));
    await renderDashboard(['dashboard']);
    await waitFor(() => expect(lowStock).toHaveBeenCalled());
    await waitFor(() => expect(document.querySelector('section.grid').textContent).not.toMatch(/Rp 0/));
    const strip = document.querySelector('section.grid');
    expect(within(strip).getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('uses the discount KPI instead of gross profit for roles without reports access, and does not fetch profit', async () => {
    await renderDashboard(['dashboard']);
    await screen.findByText('Rp 5.000');
    expect(profitReport).not.toHaveBeenCalled();
  });

  it('makes no extra API calls beyond the existing ones (no dashboard endpoint yet)', async () => {
    await renderDashboard(['dashboard', 'reports']);
    await screen.findByText('Rp 1.250.000');
    expect(listEvents).toHaveBeenCalledTimes(1);
    expect(lowStock).toHaveBeenCalledTimes(1);
    expect(listPreorders).toHaveBeenCalledTimes(1);
  });
});
