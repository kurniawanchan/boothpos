import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listOutlets } from '../../resources/js/api/outlets';
import { toLocalDateKey } from '../../resources/js/utils/date';
import { outletSettlements, stockByOutletReport, exportReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  outletSettlements: vi.fn(),
  outletSettlementTransactions: vi.fn(),
  profitReport: vi.fn(),
  outletProfitReport: vi.fn(),
  purchasesReport: vi.fn(),
  stockByOutletReport: vi.fn(),
  preorderReport: vi.fn(),
  exportReport: vi.fn(),
}));

const ROWS = [
  { id: 1, outlet_id: 5, outlet_name: 'Outlet A', total_units: 3, total_sales: '30000.00' },
  { id: null, outlet_id: 6, outlet_name: 'Outlet B', total_units: 0, total_sales: '0.00' },
];

function renderReports(menuKeys = ['dashboard', 'reports']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  return render(ReportsView, { global: { plugins: [pinia] } });
}

// 041 (US2) — Laporan memakai kerangka halaman baru: PageHeader, TabBar, penghitung "N data", keadaan galat.
describe('ReportsView — page kit structure (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: [] });
    outletSettlements.mockResolvedValue({ data: ROWS });
  });

  it('renders the page header (single h1) with the export action and a tablist with all five report tabs', async () => {
    renderReports();
    await screen.findByText('Outlet A');

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1, name: 'Laporan' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ekspor \.xlsx/i })).toBeInTheDocument();

    const tabs = within(screen.getByRole('tablist')).getAllByRole('tab');
    expect(tabs).toHaveLength(5);
    expect(tabs[0]).toHaveAttribute('aria-selected', 'true');
  });

  it('the "N data" counter equals the rows actually shown', async () => {
    renderReports();
    await screen.findByText('Outlet A');
    expect(screen.getByTestId('data-count')).toHaveTextContent('2 data');
    expect(document.querySelectorAll('tbody tr')).toHaveLength(2);
  });

  it('exports the active tab with the server report name', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('Outlet A');
    await user.click(screen.getByRole('button', { name: /ekspor \.xlsx/i }));
    expect(exportReport).toHaveBeenCalledWith('outlet-settlements', { date_from: toLocalDateKey(new Date()), date_to: toLocalDateKey(new Date()) });
  });

  it('shows an error state with a retry button that reloads the tab', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    outletSettlements.mockRejectedValueOnce(new Error('network'));
    renderReports();

    expect(await screen.findByRole('alert')).toHaveTextContent(/gagal memuat/i);
    expect(screen.queryByTestId('data-count')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Outlet A')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('hides the export action and tabs for roles without reports access', async () => {
    renderReports(['dashboard', 'pos']);
    await screen.findAllByRole('combobox');
    expect(screen.queryByRole('button', { name: /ekspor \.xlsx/i })).not.toBeInTheDocument();
    expect(screen.queryAllByRole('tab')).toHaveLength(0);
  });

  it('stock-by-outlet tab counts the rows shown', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    stockByOutletReport.mockResolvedValue({ data: [{ outlet_id: 5, outlet_name: 'Outlet A', variant_count: 2, total_stock: 10 }] });
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Stok per Outlet' }));
    await screen.findByText('Outlet A');
    await waitFor(() => expect(screen.getByTestId('data-count')).toHaveTextContent('1 data'));
  });
});
