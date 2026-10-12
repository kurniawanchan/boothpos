import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { outletSettlements, outletSettlementTransactions, outletProfitReport, profitReport } from '../../resources/js/api/reports';
import { listOutlets } from '../../resources/js/api/outlets';
import { toLocalDateKey } from '../../resources/js/utils/date';

vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  outletSettlements: vi.fn(),
  outletSettlementTransactions: vi.fn(),
  profitReport: vi.fn(),
  outletProfitReport: vi.fn(),
  purchasesReport: vi.fn(),
  stockByOutletReport: vi.fn(),
  exportReport: vi.fn(),
}));

// 046: periode laporan = rentang tanggal bisnis, bawaan hari ini.
const TODAY = toLocalDateKey(new Date());

function renderReports() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'reports'] };
  return render(ReportsView, { global: { plugins: [pinia] } });
}

// F11.6 — drill-down transaction detail from the Rekap Outlet tab.
describe('ReportsView — outlet transaction drill-down (F11.6)', () => {
  const SETTLEMENT_ROWS = [
    { id: 1, outlet_id: 5, outlet_name: 'Outlet A', total_units: 3, total_sales: '90000.00', payable_amount: '90000.00', paid_amount: '0.00', outstanding: '90000.00', status: 'unpaid' },
  ];

  const DRILLDOWN_RESPONSE = {
    outlet: { id: 5, name: 'Outlet A' },
    transactions: [
      {
        key: 'order-201',
        number: 'ORD-201',
        source: 'order',
        created_at: '2026-09-01T09:00:00Z',
        items: [{ sku: 'ABCST0001', name: 'Stiker Holografik', qty: 3, line_total: '90000.00' }],
        amount_for_outlet: '90000.00',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: [] });
    outletSettlements.mockResolvedValue({ data: SETTLEMENT_ROWS });
    outletSettlementTransactions.mockResolvedValue(DRILLDOWN_RESPONSE);
  });

  it('opens the drill-down modal and renders only this outlet\'s isolated order items, trusting the backend filter as-is', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    // "Rekap Outlet" is now the default active tab (Penjualan moved out
    // to its own page), so its data loads on mount without a click.
    await screen.findByText('Outlet A');
    await user.click(screen.getByRole('button', { name: 'Detail transaksi' }));
    await waitFor(() => expect(outletSettlementTransactions).toHaveBeenCalledWith(5, { date_from: TODAY, date_to: TODAY }));
    expect(await screen.findByText('ORD-201')).toBeInTheDocument();
    expect(screen.getByText('Stiker Holografik')).toBeInTheDocument();
    // Only 1 item row rendered — the response already isolated this
    // outlet's line items, so the modal must not re-filter or duplicate.
    expect(screen.getAllByText('ABCST0001')).toHaveLength(1);
  });

  it('is hidden for non-owner/admin roles because the Rekap Outlet tab itself is hidden for them', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore();
    auth.user = { id: 2, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos'] };
    render(ReportsView, { global: { plugins: [pinia] } });
    await screen.findAllByRole('combobox'); // filter (periode / outlet) — always rendered regardless of role
    expect(screen.queryByText('Rekap Outlet')).not.toBeInTheDocument();
  });
});

// F9.5 — per-outlet gross profit view, deliberately excluding event_cost.
describe('ReportsView — outlet profit tab (F9.5)', () => {
  const OUTLET_PROFIT_RESPONSE = {
    data: [
      { outlet_id: 5, outlet_name: 'Outlet A', total_sales: '90000.00', modal: '30000.00', gross_profit: '60000.00' },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: [] });
    outletSettlements.mockResolvedValue({ data: [] });
    outletProfitReport.mockResolvedValue(OUTLET_PROFIT_RESPONSE);
  });

  it('is visible for owner/admin and renders real per-outlet gross-profit figures', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    const tab = await screen.findByRole('tab', { name: 'Modal Outlet' });
    await user.click(tab);
    await waitFor(() => expect(outletProfitReport).toHaveBeenCalledWith({ date_from: TODAY, date_to: TODAY }));
    expect(await screen.findByText('Outlet A')).toBeInTheDocument();
    // Rp 60.000 appears twice with only one row: once in the data row, once
    // in the new Grand Total footer row (which mirrors the single row's own
    // total when there's nothing else to sum).
    expect(screen.getAllByText('Rp 60.000')).toHaveLength(2);
  });

  it('has no net-profit or event-cost figure (046: no events)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Modal Outlet' }));
    await screen.findByText('Outlet A');
    expect(screen.queryByText(/biaya event/i)).not.toBeInTheDocument();
  });

  it('is hidden entirely for cashier/inventory roles', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore();
    auth.user = { id: 3, role: 'Inventory', name: 'Gudang', menu_keys: ['dashboard', 'products', 'stock'] };
    render(ReportsView, { global: { plugins: [pinia] } });
    await screen.findAllByRole('combobox'); // filter (periode / outlet) — always rendered regardless of role
    expect(screen.queryByText('Modal Outlet')).not.toBeInTheDocument();
  });
});

// 040 — Rekap Outlet HANYA menghitung penjualan POS: tidak ada lagi kolom POS/pre-order,
// dan Grand Total menjumlah baris yang tampil. (Membalik pengujian 033 di tempat ini.)
describe('ReportsView — Rekap Outlet POS-saja (040)', () => {
  const POS_ROWS = [
    { id: 1, outlet_id: 5, outlet_name: 'Outlet A', total_units: 3, total_sales: '30000.00', deduction: '0.00', payable_amount: '30000.00', paid_amount: '10000.00', outstanding: '20000.00', status: 'partial' },
    { id: null, outlet_id: 6, outlet_name: 'Outlet B', total_units: 0, total_sales: '0.00', deduction: '0.00', payable_amount: '0.00', paid_amount: '0.00', outstanding: '0.00', status: 'unpaid' },
    { id: 3, outlet_id: 7, outlet_name: 'Outlet C', total_units: 2, total_sales: '25000.00', deduction: '0.00', payable_amount: '25000.00', paid_amount: '25000.00', outstanding: '0.00', status: 'paid' },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: [] });
  });

  it('shows only Outlet, Unit and Sales (no pre-order, payable, paid, outstanding or status columns) and a Grand Total that adds up the rows', async () => {
    outletSettlements.mockResolvedValue({ data: POS_ROWS });
    renderReports();
    await screen.findByText('Outlet A');

    for (const gone of ['Unit POS', 'Unit pre-order', 'Penjualan POS', 'Penjualan pre-order', 'Wajib dibayar', 'Sudah dibayar', 'Sisa', 'Status']) {
      expect(screen.queryByRole('columnheader', { name: gone })).not.toBeInTheDocument();
    }
    for (const kept of ['Outlet', 'Unit', 'Penjualan']) {
      expect(screen.getByRole('columnheader', { name: kept })).toBeInTheDocument();
    }

    const grand = screen.getByText('Grand Total').closest('tr');
    const cells = [...grand.querySelectorAll('td')].map((td) => td.textContent.replace(/\s+/g, ' ').trim());
    // [label, unit, sales, actions]
    expect(cells[1]).toBe('5');
    expect(cells[2]).toMatch(/55\.000/);
  });

  it('keeps listing an outlet without sales (zeros) and never offers Record payment or a status', async () => {
    outletSettlements.mockResolvedValue({ data: POS_ROWS });
    renderReports();
    await screen.findByText('Outlet B');

    expect(screen.queryByText(/Catat bayar|Record payment/i)).not.toBeInTheDocument();
    const rowOf = (name) => screen.getByText(name).closest('tr');
    for (const name of ['Outlet A', 'Outlet B', 'Outlet C']) {
      expect(rowOf(name).textContent).not.toMatch(/unpaid|partial|paid/i);
      expect(rowOf(name).textContent).toMatch(/Detail transaksi|Transaction detail/i);
    }
  });

  it('the outlet filter narrows the rows and the Grand Total follows it', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listOutlets.mockResolvedValue({ data: [{ id: 5, name: 'Outlet A' }, { id: 7, name: 'Outlet C' }] });
    outletSettlements.mockResolvedValue({ data: POS_ROWS });
    renderReports();
    await screen.findByText('Outlet A');

    const outletFilter = screen.getAllByRole('combobox').find((c) => /semua outlet/i.test(c.textContent));
    await user.click(outletFilter);
    await user.click(await screen.findByRole('option', { name: 'Outlet C' }));

    await waitFor(() => expect(screen.queryByText('Outlet A')).not.toBeInTheDocument());
    const grand = screen.getByText('Grand Total').closest('tr');
    const cells = [...grand.querySelectorAll('td')].map((td) => td.textContent.replace(/\s+/g, ' ').trim());
    expect(cells[1]).toBe('2');
    expect(cells[2]).toMatch(/25\.000/);
  });
});

// 046 — Modal & Untung: tiga angka (pendapatan, modal, laba kotor) untuk periode; tanpa pre-order, biaya event,
// atau laba bersih.
describe('ReportsView — Modal & Untung per periode (046)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: [] });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('shows exactly revenue, cost of goods and gross profit', async () => {
    profitReport.mockResolvedValue({ revenue: '35000.00', cost_of_goods: '14000.00', gross_profit: '21000.00' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Modal & Untung' }));
    expect(await screen.findByText('Rp 35.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 14.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 21.000')).toBeInTheDocument();
    expect(screen.queryByText(/pre-order/i)).not.toBeInTheDocument();
    await waitFor(() => expect(profitReport).toHaveBeenCalledWith({ date_from: TODAY, date_to: TODAY }));
  });

  it('reloads with the new range when the period changes', async () => {
    profitReport.mockResolvedValue({ revenue: '0.00', cost_of_goods: '0.00', gross_profit: '0.00' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Modal & Untung' }));
    await waitFor(() => expect(profitReport).toHaveBeenCalled());

    const from = await screen.findByTestId('period-from');
    await user.clear(from);
    await user.type(from, '2026-09-01');
    await waitFor(() => expect(profitReport).toHaveBeenLastCalledWith(expect.objectContaining({ date_from: '2026-09-01' })));
  });

  it('has no Pre-order tab', async () => {
    renderReports();
    await screen.findByRole('tab', { name: 'Modal & Untung' });
    expect(screen.queryByRole('tab', { name: /pre-order/i })).not.toBeInTheDocument();
  });
});
