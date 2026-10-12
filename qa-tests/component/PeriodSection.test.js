import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import PeriodSection from '../../resources/js/components/dashboard/PeriodSection.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { salesReport, profitReport, outletSettlements } from '../../resources/js/api/reports';
import { lowStock } from '../../resources/js/api/stock';
import { toLocalDateKey } from '../../resources/js/utils/date';

vi.mock('../../resources/js/api/reports', () => ({
  salesReport: vi.fn(),
  profitReport: vi.fn(),
  outletSettlements: vi.fn(),
}));
vi.mock('../../resources/js/api/stock', () => ({ lowStock: vi.fn() }));
// chart.js (kanvas) tidak cocok dengan jsdom: komponen grafik di-stub supaya yang diuji adalah pengambilan data dan DOM-nya.
vi.mock('vue-chartjs', () => ({
  Bar: { template: '<div data-testid="bar-chart" />' },
  Doughnut: { template: '<div data-testid="doughnut-chart" />' },
}));

const ROUTE_NAMES = ['dashboard', 'pos', 'stock', 'products', 'reports'];
const TODAY = toLocalDateKey(new Date());

async function renderSection(menuKeys) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'Test', username: 'test', role: 'Owner', menu_keys: menuKeys };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ROUTE_NAMES.map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  router.push('/dashboard');
  await router.isReady();
  return render(PeriodSection, { global: { plugins: [pinia, router] } });
}

const EMPTY_SALES = { totals: { net_sales: '0.00', order_count: 0, unit_count: 0, discount_total: '0.00' }, rows: [] };
const SALES = { totals: { net_sales: '1250000.00', order_count: 7, unit_count: 19, discount_total: '5000.00' }, rows: [] };

// 046 — "Per event" menjadi "Rincian periode": tanpa event aktif, grafik per event, atau panel pre-order; semua panel
// mengikuti rentang tanggal bisnis (bawaan hari ini).
describe('PeriodSection — shortcuts, period filter, charts, links', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    salesReport.mockResolvedValue(EMPTY_SALES);
    lowStock.mockResolvedValue({ data: [] });
    profitReport.mockResolvedValue({ gross_profit: '0.00' });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('shows only the shortcuts the current role has menu access to, and never a pre-order shortcut', async () => {
    await renderSection(['dashboard', 'pos']);
    await waitFor(() => expect(salesReport).toHaveBeenCalled());

    expect(screen.getByText('Transaksi Baru')).toBeInTheDocument();
    expect(screen.queryByText('Tambah Produk')).not.toBeInTheDocument();
    expect(screen.queryByText('Sesuaikan Stok')).not.toBeInTheDocument();
    expect(screen.queryByText(/pre-order/i)).not.toBeInTheDocument();
  });

  it('loads every panel for today by default, without any event parameter', async () => {
    await renderSection(['dashboard']);
    await waitFor(() => expect(salesReport).toHaveBeenCalledWith({ group_by: 'category', date_from: TODAY, date_to: TODAY }));
    for (const call of salesReport.mock.calls) {
      expect(call[0]).not.toHaveProperty('event_id');
    }
  });

  it('re-fetches the sales panel with the chosen date range', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderSection(['dashboard']);
    await waitFor(() => expect(salesReport).toHaveBeenCalled());
    salesReport.mockClear();

    const [fromInput] = screen.getAllByDisplayValue(TODAY);
    await user.clear(fromInput);
    await user.type(fromInput, '2026-09-01');

    await waitFor(() =>
      expect(salesReport).toHaveBeenCalledWith(expect.objectContaining({ group_by: 'day', date_from: '2026-09-01' }))
    );
  });

  it('renders an empty state for the category/outlet charts when there is no data, and has no per-event chart', async () => {
    await renderSection(['dashboard']);
    await screen.findByText('Penjualan per kategori');
    expect(screen.getByText('Penjualan per outlet')).toBeInTheDocument();
    expect(screen.queryByText('Penjualan per event')).not.toBeInTheDocument();

    await waitFor(() => expect(screen.getAllByText(/belum ada penjualan tercatat/i).length).toBeGreaterThanOrEqual(2));
  });

  it('offers a drill-through link on the sales and stock sections, and none to pre-orders or events', async () => {
    await renderSection(['dashboard']);
    await screen.findByText(/lihat pergerakan stok/i);

    expect(screen.getAllByText(/lihat laporan lengkap/i).length).toBeGreaterThan(0);
    expect(screen.queryByText(/pre-order/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/lihat event/i)).not.toBeInTheDocument();
  });
});

describe('PeriodSection — KPI strip', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    salesReport.mockResolvedValue(SALES);
    lowStock.mockResolvedValue({ data: [{ variant_id: 1, product_name: 'P', sku: 'S1', current_stock: 0, low_stock_alert: 3 }, { variant_id: 2, product_name: 'Q', sku: 'S2', current_stock: 2, low_stock_alert: 3 }] });
    profitReport.mockResolvedValue({ gross_profit: '400000.00' });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('has exactly four KPI cells whose values match the loaded data', async () => {
    await renderSection(['dashboard', 'pos', 'reports']);
    expect(screen.getByRole('heading', { level: 2, name: 'Rincian periode' })).toBeInTheDocument();
    await screen.findByText('Rp 1.250.000');
    const strip = document.querySelector('section.grid');
    expect(strip.children).toHaveLength(4); // penjualan bersih, transaksi, laba kotor, stok menipis
    expect(within(strip).getByText('Rp 1.250.000')).toBeInTheDocument();
    expect(within(strip).getByText('7')).toBeInTheDocument();
    expect(await within(strip).findByText('Rp 400.000')).toBeInTheDocument();
    expect(within(strip).getByText('2')).toBeInTheDocument();
  });

  it('shows "—" (not 0) for a figure that could not be loaded', async () => {
    salesReport.mockRejectedValue(new Error('down'));
    lowStock.mockRejectedValue(new Error('down'));
    await renderSection(['dashboard']);
    await waitFor(() => expect(lowStock).toHaveBeenCalled());
    await waitFor(() => expect(document.querySelector('section.grid').textContent).not.toMatch(/Rp 0/));
    expect(within(document.querySelector('section.grid')).getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('uses the discount KPI instead of gross profit for roles without reports access, and does not fetch profit', async () => {
    await renderSection(['dashboard']);
    await screen.findByText('Rp 5.000');
    expect(profitReport).not.toHaveBeenCalled();
  });

  it('makes one call per panel and sends the period to the report panels', async () => {
    await renderSection(['dashboard', 'reports']);
    await screen.findByText('Rp 1.250.000');
    expect(lowStock).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(profitReport).toHaveBeenCalledWith({ date_from: TODAY, date_to: TODAY }));
    expect(outletSettlements).toHaveBeenCalledWith({ date_from: TODAY, date_to: TODAY });
  });
});
