import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import PerEventSection from '../../resources/js/components/dashboard/PerEventSection.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { salesReport, profitReport, outletSettlements } from '../../resources/js/api/reports';
import { lowStock } from '../../resources/js/api/stock';
import { listPreorders } from '../../resources/js/api/preorders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  salesReport: vi.fn(),
  profitReport: vi.fn(),
  outletSettlements: vi.fn(),
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

async function renderSection(menuKeys) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, name: 'Test', username: 'test', role: 'Owner', menu_keys: menuKeys };
  const router = makeRouter();
  router.push('/dashboard');
  await router.isReady();
  return render(PerEventSection, { global: { plugins: [pinia, router] } });
}

// 042-dashboard-revamp (T025) — isi Dashboard LAMA dipindahkan ke PerEventSection; asersi 005-US2 di bawah
// dipindahkan UTUH (bukan dilemahkan) dan kini merender komponen itu langsung.
describe('PerEventSection — shortcuts, day filter, charts, links (moved from DashboardView, 005 US2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [] });
    salesReport.mockResolvedValue(EMPTY_SALES);
    lowStock.mockResolvedValue({ data: [] });
    listPreorders.mockResolvedValue({ data: [], meta: { total: 0 } });
    profitReport.mockResolvedValue({ gross_profit: '0.00' });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('shows only the shortcuts the current role has menu access to', async () => {
    await renderSection(['dashboard', 'pos']);
    await waitFor(() => expect(salesReport).toHaveBeenCalled());

    expect(screen.getByText('Transaksi Baru')).toBeInTheDocument();
    expect(screen.queryByText('Tambah Produk')).not.toBeInTheDocument();
    expect(screen.queryByText('Sesuaikan Stok')).not.toBeInTheDocument();
  });

  it('re-fetches the sales panel with the chosen date range', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderSection(['dashboard']);
    await waitFor(() => expect(salesReport).toHaveBeenCalled());
    salesReport.mockClear();

    const [fromInput, toInput] = screen.getAllByDisplayValue('');
    await user.type(fromInput, '2026-09-01');

    await waitFor(() =>
      expect(salesReport).toHaveBeenLastCalledWith(expect.objectContaining({ group_by: 'day', date_from: '2026-09-01' }))
    );
  });

  it('renders an empty state for the category/outlet/event charts when there is no data', async () => {
    await renderSection(['dashboard']);
    await screen.findByText('Penjualan per kategori');
    expect(screen.getByText('Penjualan per outlet')).toBeInTheDocument();
    expect(screen.getByText('Penjualan per event')).toBeInTheDocument();

    await waitFor(() => expect(screen.getAllByText(/belum ada penjualan tercatat/i).length).toBeGreaterThanOrEqual(3));
  });

  it('offers a drill-through link on the sales-per-day and stock sections', async () => {
    await renderSection(['dashboard']);
    await screen.findByText(/lihat pergerakan stok/i);

    expect(screen.getAllByText(/lihat laporan lengkap/i).length).toBeGreaterThan(0);
    expect(screen.getByText(/lihat semua pre-order/i)).toBeInTheDocument();
  });
});

const SALES = { totals: { net_sales: '1250000.00', order_count: 7, unit_count: 19, discount_total: '5000.00' }, rows: [] };

// 041 (US2) — Dashboard: PageHeader + KpiStrip dengan angka yang SAMA dari data yang sama.
describe('PerEventSection — per-event KPI strip (moved from the 041 Dashboard page kit)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES);
    lowStock.mockResolvedValue({ data: [{ variant_id: 1, product_name: 'P', sku: 'S1', current_stock: 0, low_stock_alert: 3 }, { variant_id: 2, product_name: 'Q', sku: 'S2', current_stock: 2, low_stock_alert: 3 }] });
    listPreorders.mockResolvedValue({ data: [], meta: { total: 0 } });
    profitReport.mockResolvedValue({ gross_profit: '400000.00' });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('has exactly four KPI cells whose values match the loaded data', async () => {
    await renderSection(['dashboard', 'pos', 'reports']);
    expect(screen.getByRole('heading', { level: 2, name: 'Per event' })).toBeInTheDocument();
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
    await renderSection(['dashboard']);
    await waitFor(() => expect(lowStock).toHaveBeenCalled());
    await waitFor(() => expect(document.querySelector('section.grid').textContent).not.toMatch(/Rp 0/));
    const strip = document.querySelector('section.grid');
    expect(within(strip).getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('uses the discount KPI instead of gross profit for roles without reports access, and does not fetch profit', async () => {
    await renderSection(['dashboard']);
    await screen.findByText('Rp 5.000');
    expect(profitReport).not.toHaveBeenCalled();
  });

  it('makes no extra API calls beyond the existing ones (042: the per-event section keeps its own calls)', async () => {
    await renderSection(['dashboard', 'reports']);
    await screen.findByText('Rp 1.250.000');
    expect(listEvents).toHaveBeenCalledTimes(1);
    expect(lowStock).toHaveBeenCalledTimes(1);
    expect(listPreorders).toHaveBeenCalledTimes(1);
  });
});
