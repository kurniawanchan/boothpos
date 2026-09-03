import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import DashboardView from '../../resources/js/views/DashboardView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { salesReport, profitReport, artistSettlements } from '../../resources/js/api/reports';
import { lowStock } from '../../resources/js/api/stock';
import { listPreorders } from '../../resources/js/api/preorders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  salesReport: vi.fn(),
  profitReport: vi.fn(),
  artistSettlements: vi.fn(),
}));
vi.mock('../../resources/js/api/stock', () => ({ lowStock: vi.fn() }));
vi.mock('../../resources/js/api/preorders', () => ({ listPreorders: vi.fn() }));
// chart.js's Canvas-based resize/animation lifecycle doesn't play well
// with jsdom teardown (getComputedStyle on a detached canvas throws) —
// stub the two chart components so this test exercises the surrounding
// data-fetching/DOM logic without touching the real canvas rendering.
vi.mock('vue-chartjs', () => ({
  Bar: { template: '<div data-testid="bar-chart" />' },
  Doughnut: { template: '<div data-testid="doughnut-chart" />' },
}));

const ROUTE_NAMES = ['dashboard', 'pos', 'preorders', 'stock', 'products', 'reports', 'events'];

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: ROUTE_NAMES.map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
}

const EMPTY_SALES = { totals: { net_sales: '0.00', order_count: 0, unit_count: 0, discount_total: '0.00' }, rows: [] };

async function renderDashboard(menuKeys) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, name: 'Test', username: 'test', role: 'Owner', menu_keys: menuKeys };
  const router = makeRouter();
  router.push('/dashboard');
  await router.isReady();
  return render(DashboardView, { global: { plugins: [pinia, router] } });
}

// 005-ux-enhancements-dashboard (US2)
describe('DashboardView — shortcuts, day filter, charts, links', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [] });
    salesReport.mockResolvedValue(EMPTY_SALES);
    lowStock.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: [], meta: { total: 0 } });
    profitReport.mockResolvedValue({ gross_profit: '0.00' });
    artistSettlements.mockResolvedValue({ data: [] });
  });

  it('shows only the shortcuts the current role has menu access to', async () => {
    await renderDashboard(['dashboard', 'pos']);
    await waitFor(() => expect(salesReport).toHaveBeenCalled());

    expect(screen.getByText('Transaksi Baru')).toBeInTheDocument();
    expect(screen.queryByText('Tambah Produk')).not.toBeInTheDocument();
    expect(screen.queryByText('Sesuaikan Stok')).not.toBeInTheDocument();
  });

  it('re-fetches the sales panel with the chosen date range', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderDashboard(['dashboard']);
    await waitFor(() => expect(salesReport).toHaveBeenCalled());
    salesReport.mockClear();

    const [fromInput, toInput] = screen.getAllByDisplayValue('');
    await user.type(fromInput, '2026-09-01');

    await waitFor(() =>
      expect(salesReport).toHaveBeenLastCalledWith(expect.objectContaining({ group_by: 'day', date_from: '2026-09-01' }))
    );
  });

  it('renders an empty state for the category/artist/event charts when there is no data', async () => {
    await renderDashboard(['dashboard']);
    await screen.findByText('Penjualan per kategori');
    expect(screen.getByText('Penjualan per artist')).toBeInTheDocument();
    expect(screen.getByText('Penjualan per event')).toBeInTheDocument();

    await waitFor(() => expect(screen.getAllByText(/belum ada penjualan tercatat/i).length).toBeGreaterThanOrEqual(3));
  });

  it('offers a drill-through link on the sales-per-day and stock sections', async () => {
    await renderDashboard(['dashboard']);
    await screen.findByText(/lihat pergerakan stok/i);

    expect(screen.getAllByText(/lihat laporan lengkap/i).length).toBeGreaterThan(0);
    expect(screen.getByText(/lihat semua pre-order/i)).toBeInTheDocument();
  });
});
