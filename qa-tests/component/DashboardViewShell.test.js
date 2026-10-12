import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import DashboardView from '../../resources/js/views/DashboardView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useToastStore } from '../../resources/js/stores/toast';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { salesReport, profitReport, outletSettlements } from '../../resources/js/api/reports';
import { lowStock } from '../../resources/js/api/stock';
import { summary, attention, orders, exportDashboard } from '../../resources/js/api/dashboard';

vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), profitReport: vi.fn(), outletSettlements: vi.fn() }));
vi.mock('../../resources/js/api/stock', () => ({ lowStock: vi.fn(), listMovements: vi.fn() }));
vi.mock('../../resources/js/api/dashboard', () => ({ summary: vi.fn(), attention: vi.fn(), orders: vi.fn(), downloadOrdersCsv: vi.fn(), exportDashboard: vi.fn() }));
vi.mock('vue-chartjs', () => ({ Bar: { template: '<div />' }, Doughnut: { template: '<div />' } }));

const NAMES = ['dashboard', 'pos', 'preorders', 'stock', 'products', 'reports', 'events', 'sales', 'activity-log', 'session'];

function summaryFor(period, net = '1000000.00') {
  return {
    computed_at: '2026-10-11T12:30:04+07:00',
    business_date: '2026-10-11',
    period,
    comparison: period === 'today' ? { basis: 'same_time_yesterday', up_to: '12:30' } : { basis: 'previous_period' },
    kpis: {
      net_sales: { value: net, comparison: '900000.00', change_pct: 11.1, series: [], comparison_series: [] },
      orders: { value: 10, comparison: 9, change_pct: 11.1, series: [], comparison_series: [] },
      avg_order_value: { value: '100000.00', comparison: '100000.00', change_pct: 0, series: [], comparison_series: [] },
      low_stock: { value: 2, comparison: 2, change_abs: 0, scope: 'organisation', series: [], comparison_series: [] },
    },
  };
}

const ATTENTION = {
  computed_at: '2026-10-11T12:30:04+07:00',
  business_date: '2026-10-11',
  count: 1,
  items: [
    { kind: 'payments_pending', severity: 'warn', count: 4, amount: '75900.00', meta: { by_method: { qr_ewallet: 3, bank_transfer: 1 } }, link: { route: 'sales', query: { pstate: 'pending' } } },
    { kind: 'void_after_fire', severity: 'ok', count: 0, amount: null, meta: {}, link: null },
    { kind: 'cash_difference', severity: 'ok', count: 0, amount: null, meta: {}, link: null },
    { kind: 'low_stock', severity: 'ok', count: 0, amount: null, meta: {}, link: null },
  ],
};
const ORDERS = {
  data: [{ id: 812, order_number: 'A000141', type: 'dine_in', table: 'T02', items_summary: 'Kopi susu ×2', status: 'completed', tab: 'paid', payment_state: 'paid', methods: ['qr_ewallet'], total_amount: '75900.00', paid_amount: '75900.00', at: '2026-10-11T12:21:09+07:00' }],
  meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
  counts: { all: 1, open: 0, partial: 0, paid: 1, cancelled: 0 },
  hidden_tabs: ['bill', 'closed'],
};

async function renderDashboard(url = '/dashboard') {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'T', username: 't', role: 'Owner', menu_keys: ['dashboard', 'pos'] };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: NAMES.map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  router.push(url);
  await router.isReady();
  const view = render(DashboardView, { global: { plugins: [pinia, router] } });
  return { ...view, router, pinia };
}

describe('DashboardView — shell: period, regions, export (042 T025)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    summary.mockImplementation(({ period }) => Promise.resolve(summaryFor(period)));
    salesReport.mockResolvedValue({ totals: { net_sales: '0.00', order_count: 0, unit_count: 0, discount_total: '0.00' }, rows: [] });
    lowStock.mockResolvedValue({ data: [] });
    profitReport.mockResolvedValue({ gross_profit: '0.00' });
    outletSettlements.mockResolvedValue({ data: [] });
    exportDashboard.mockResolvedValue();
    attention.mockResolvedValue(ATTENTION);
    orders.mockResolvedValue(ORDERS);
  });

  describe('header', () => {
    it('shows the business date and the "Diperbarui HH.MM" marker in the store timezone', async () => {
      await renderDashboard();
      const date = await screen.findByTestId('business-date');
      expect(date).toHaveTextContent(/11 Okt 2026/);
      expect(date).toHaveTextContent('tanggal bisnis');
      await waitFor(() => expect(screen.getByTestId('updated-at')).toHaveTextContent('Diperbarui 12.30'));
    });

    it('renders the updated time in the STORE timezone, not the browser one', async () => {
      const { pinia } = await renderDashboard();
      await screen.findByText('Diperbarui 12.30');
      // 12:30 WIB (+07:00) == 14:30 WITA/Asia/Makassar (+08:00 -> 13.30). Mengganti zona toko menggeser jamnya.
      useSettingsStore(pinia).storeTimezone = 'Asia/Jayapura'; // +09:00
      await screen.findByText('Diperbarui 14.30');
    });

    it('045: names the outlet scope only when there are several outlets', async () => {
      const { pinia } = await renderDashboard();
      await screen.findByTestId('business-date');
      expect(screen.queryByTestId('dashboard-scope')).toBeNull();

      const outlets = useOutletStore(pinia);
      Object.assign(outlets, { outlets: [{ id: 1, code: 'WJ1', name: 'Warkop 1' }, { id: 2, code: 'WJ2', name: 'Warkop 2' }], multi: true, selected: null });
      expect(await screen.findByTestId('dashboard-scope')).toHaveTextContent('Semua outlet · 2');
      outlets.selected = 2;
      await waitFor(() => expect(screen.getByTestId('dashboard-scope')).toHaveTextContent('Outlet: Warkop 2'));
    });

    it('has an Export button next to the period control', async () => {
      await renderDashboard();
      expect(screen.getByTestId('export-dashboard')).toHaveTextContent('Ekspor');
      expect(screen.getByRole('tablist', { name: 'Periode' })).toBeInTheDocument();
    });
  });

  describe('period control', () => {
    it('defaults to today, with the period tab selected and summary requested for "today"', async () => {
      await renderDashboard();
      expect(screen.getByTestId('period-today')).toHaveAttribute('aria-selected', 'true');
      await waitFor(() => expect(summary).toHaveBeenCalledWith({ period: 'today' }));
    });

    it('switching to 7 hari calls summary with 7d, reflects it in the address, and shows the new data', async () => {
      const user = userEvent.setup();
      summary.mockImplementation(({ period }) => Promise.resolve(summaryFor(period, period === '7d' ? '7000000.00' : '1000000.00')));
      const { router } = await renderDashboard();
      await screen.findByText('Rp 1.000.000');

      await user.click(screen.getByRole('tab', { name: '7 hari' }));
      await waitFor(() => expect(summary).toHaveBeenLastCalledWith({ period: '7d' }));
      expect(await screen.findByText('Rp 7.000.000')).toBeInTheDocument();
      expect(screen.getByTestId('period-7d')).toHaveAttribute('aria-selected', 'true');
      expect(router.currentRoute.value.query.period).toBe('7d');
      expect(screen.getAllByText('vs periode sebelumnya').length).toBeGreaterThan(0);
    });

    it('survives a reload: the period in the address is restored and requested first', async () => {
      await renderDashboard('/dashboard?period=30d');
      expect(screen.getByTestId('period-30d')).toHaveAttribute('aria-selected', 'true');
      await waitFor(() => expect(summary).toHaveBeenCalled());
      expect(summary.mock.calls[0][0]).toEqual({ period: '30d' });
      expect(summary.mock.calls.every(([p]) => p.period === '30d')).toBe(true);
    });

    it('ignores an unknown period in the address', async () => {
      await renderDashboard('/dashboard?period=yearly');
      expect(screen.getByTestId('period-today')).toHaveAttribute('aria-selected', 'true');
      await waitFor(() => expect(summary).toHaveBeenCalledWith({ period: 'today' }));
    });

    it('ignores a stale response from a period the user already left', async () => {
      const user = userEvent.setup();
      let resolveToday;
      summary.mockImplementation(({ period }) =>
        period === 'today'
          ? new Promise((res) => { resolveToday = () => res(summaryFor('today', '1111111.00')); })
          : Promise.resolve(summaryFor(period, '7000000.00')));
      await renderDashboard();
      await user.click(screen.getByRole('tab', { name: '7 hari' }));
      await screen.findByText('Rp 7.000.000');
      resolveToday();
      await new Promise((r) => setTimeout(r, 0));
      expect(screen.queryByText('Rp 1.111.111')).not.toBeInTheDocument();
      expect(screen.getByText('Rp 7.000.000')).toBeInTheDocument();
    });
  });

  describe('attention and orders regions', () => {
    it('mounts the attention panel with the "Seluruh toko" scope and the orders table under the KPIs', async () => {
      await renderDashboard();
      const panel = await screen.findByTestId('attention-panel');
      expect(await within(panel).findByText('Pembayaran menunggu verifikasi')).toBeInTheDocument();
      expect(within(panel).getByTestId('attention-scope')).toHaveTextContent('Seluruh toko · per 12.30');
      expect(within(panel).getByTestId('attention-count')).toHaveTextContent('1');
      expect(await screen.findByRole('heading', { level: 2, name: /Order terbaru/ })).toBeInTheDocument();
    });

    it('attention does NOT follow the period (it describes the current business date); orders do', async () => {
      const user = userEvent.setup();
      await renderDashboard();
      await screen.findByText('A000141');
      const attentionCalls = attention.mock.calls.length;
      await user.click(screen.getByRole('tab', { name: '7 hari' }));
      await waitFor(() => expect(orders).toHaveBeenLastCalledWith(expect.objectContaining({ period: '7d' })));
      expect(attention.mock.calls.length).toBe(attentionCalls);
    });
  });

  describe('independent regions', () => {
    it('a failing summary shows an error with Retry in the KPI region only; header and Rincian periode stay', async () => {
      const user = userEvent.setup();
      summary.mockRejectedValueOnce(new Error('down'));
      await renderDashboard();

      const kpis = await screen.findByTestId('dashboard-kpis');
      expect(await within(kpis).findByRole('alert')).toHaveTextContent('Gagal memuat angka utama');
      expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
      expect(screen.getByTestId('export-dashboard')).toBeInTheDocument();
      expect(await screen.findByRole('heading', { level: 2, name: 'Rincian periode' })).toBeInTheDocument();
      expect(await screen.findByText('Penjualan per kategori')).toBeInTheDocument();

      await user.click(within(kpis).getByRole('button', { name: /coba lagi/i }));
      expect(await within(kpis).findByText('Rp 1.000.000')).toBeInTheDocument();
      expect(within(kpis).queryByRole('alert')).toBeNull();
    });

    it('a failing attention call shows its own error with Retry; KPIs and the orders table stay', async () => {
      const user = userEvent.setup();
      attention.mockRejectedValueOnce(new Error('down'));
      await renderDashboard();

      const panel = await screen.findByTestId('attention-panel');
      expect(await within(panel).findByRole('alert')).toHaveTextContent('Gagal memuat daftar tindakan');
      expect(await within(await screen.findByTestId('dashboard-kpis')).findByText('Rp 1.000.000')).toBeInTheDocument();
      expect(await screen.findByText('A000141')).toBeInTheDocument();

      await user.click(within(panel).getByRole('button', { name: /coba lagi/i }));
      expect(await within(panel).findByText('Pembayaran menunggu verifikasi')).toBeInTheDocument();
      expect(within(panel).queryByRole('alert')).toBeNull();
    });

    it('a failing orders call shows its error inside the table region only', async () => {
      orders.mockRejectedValue(new Error('down'));
      await renderDashboard();
      const region = await screen.findByTestId('dashboard-orders');
      expect(await within(region).findByRole('alert')).toHaveTextContent('Gagal memuat order terbaru');
      expect(within(await screen.findByTestId('attention-panel')).getByText('Pembayaran menunggu verifikasi')).toBeInTheDocument();
      expect(within(await screen.findByTestId('dashboard-kpis')).getByText('Rp 1.000.000')).toBeInTheDocument();
    });

    it('failing period panel calls do not blank the KPI region', async () => {
      salesReport.mockRejectedValue(new Error('down'));
      lowStock.mockRejectedValue(new Error('down'));
      await renderDashboard();
      const kpis = await screen.findByTestId('dashboard-kpis');
      expect(await within(kpis).findByText('Rp 1.000.000')).toBeInTheDocument();
      await waitFor(() => expect(salesReport).toHaveBeenCalled());
      expect(within(kpis).getByText('Rp 1.000.000')).toBeInTheDocument();
    });
  });

  describe('auto refresh', () => {
    afterEach(() => vi.useRealTimers());

    it('refetches only the summary every 30 s, keeping the chosen period and the shown data', async () => {
      await renderDashboard('/dashboard?period=7d');
      await screen.findByText('Rp 1.000.000');
      await screen.findByText('Penjualan per kategori');
      const summaryCalls = summary.mock.calls.length;
      const panelCalls = salesReport.mock.calls.length;

      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
      // Pewaktu dibuat saat mount (waktu nyata); daftarkan ulang dengan memicu visibilitas -> arm() memakai pewaktu palsu.
      Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
      document.dispatchEvent(new Event('visibilitychange'));
      await vi.advanceTimersByTimeAsync(0);
      expect(summary.mock.calls.length).toBe(summaryCalls + 1); // refresh segera saat tab kembali terlihat
      await vi.advanceTimersByTimeAsync(30_000);
      expect(summary.mock.calls.length).toBe(summaryCalls + 2);
      expect(summary).toHaveBeenLastCalledWith({ period: '7d' });
      expect(salesReport.mock.calls.length).toBe(panelCalls); // "Rincian periode" tidak ikut di-refresh
    });

    it('the same 30 s timer also refreshes attention and the orders page, keeping the table filters', async () => {
      await renderDashboard('/dashboard?tab=open&q=kopi');
      await screen.findByText('A000141');
      const attentionCalls = attention.mock.calls.length;
      const ordersCalls = orders.mock.calls.length;
      const firstOrdersParams = orders.mock.calls[0][0];

      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
      document.dispatchEvent(new Event('visibilitychange'));
      await vi.advanceTimersByTimeAsync(0);
      await vi.advanceTimersByTimeAsync(30_000);

      expect(attention.mock.calls.length).toBeGreaterThan(attentionCalls);
      expect(orders.mock.calls.length).toBeGreaterThan(ordersCalls);
      expect(orders.mock.calls.at(-1)[0]).toEqual(firstOrdersParams); // filter dan halaman sama
      expect(screen.getByText('A000141')).toBeInTheDocument();
    });

    it('shows the Real-time marker on the orders table while the timer runs', async () => {
      await renderDashboard();
      expect(await screen.findByTestId('realtime-marker')).toBeInTheDocument();
    });

    it('a failed background refresh keeps the figures already on screen', async () => {
      await renderDashboard();
      await screen.findByText('Rp 1.000.000');
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
      document.dispatchEvent(new Event('visibilitychange'));
      summary.mockRejectedValue(new Error('down'));
      await vi.advanceTimersByTimeAsync(30_000);
      expect(screen.getByText('Rp 1.000.000')).toBeInTheDocument();
      expect(screen.queryByRole('alert')).toBeNull();
    });
  });

  describe('export', () => {
    it('calls exportDashboard with the selected period', async () => {
      const user = userEvent.setup();
      await renderDashboard('/dashboard?period=7d');
      await user.click(screen.getByTestId('export-dashboard'));
      expect(exportDashboard).toHaveBeenCalledWith({ period: '7d' });
    });

    it('shows an error toast when the export fails and re-enables the button', async () => {
      const user = userEvent.setup();
      exportDashboard.mockRejectedValue(new Error('403'));
      const { pinia } = await renderDashboard();
      await user.click(screen.getByTestId('export-dashboard'));
      await waitFor(() => expect(useToastStore(pinia).items.some((i) => i.variant === 'danger')).toBe(true));
      expect(screen.getByTestId('export-dashboard')).not.toBeDisabled();
    });
  });
});
