import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { outletSettlements, outletSettlementTransactions, outletProfitReport, profitReport } from '../../resources/js/api/reports';
import { listOutlets } from '../../resources/js/api/outlets';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
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
    event: { id: 1, name: 'Event A' },
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
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
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
    await waitFor(() => expect(outletSettlementTransactions).toHaveBeenCalledWith(5, 1));
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
    await screen.findAllByRole('combobox'); // event selector (+ outlet filter) — always rendered regardless of role
    expect(screen.queryByText('Rekap Outlet')).not.toBeInTheDocument();
  });
});

// F9.5 — per-outlet gross profit view, deliberately excluding event_cost.
describe('ReportsView — outlet profit tab (F9.5)', () => {
  const OUTLET_PROFIT_RESPONSE = {
    event: { id: 1, name: 'Event A' },
    data: [
      { outlet_id: 5, outlet_name: 'Outlet A', total_sales: '90000.00', modal: '30000.00', gross_profit: '60000.00' },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
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
    await waitFor(() => expect(outletProfitReport).toHaveBeenCalledWith(1));
    expect(await screen.findByText('Outlet A')).toBeInTheDocument();
    // Rp 60.000 appears twice with only one row: once in the data row, once
    // in the new Grand Total footer row (which mirrors the single row's own
    // total when there's nothing else to sum).
    expect(screen.getAllByText('Rp 60.000')).toHaveLength(2);
  });

  it('shows a note that the figure excludes event_cost, not a net-profit figure', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Modal Outlet' }));
    expect(await screen.findByText(/belum dikurangi biaya event/i)).toBeInTheDocument();
  });

  it('is hidden entirely for cashier/inventory roles', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore();
    auth.user = { id: 3, role: 'Inventory', name: 'Gudang', menu_keys: ['dashboard', 'products', 'stock'] };
    render(ReportsView, { global: { plugins: [pinia] } });
    await screen.findAllByRole('combobox'); // event selector (+ outlet filter) — always rendered regardless of role
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
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
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

// 033 — Modal & Untung dan Modal Outlet: total + rincian POS/pre-order.
describe('ReportsView — POS vs pre-order di laporan modal (033)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    listOutlets.mockResolvedValue({ data: [] });
    outletSettlements.mockResolvedValue({ data: [] });
  });

  it('Modal & Untung: kartu pendapatan/modal/laba kotor memuat sub-baris POS · Pre-order, biaya event & laba bersih tidak', async () => {
    profitReport.mockResolvedValue({
      event: { id: 1 },
      revenue: '35000.00', cost_of_goods: '14000.00', gross_profit: '21000.00', event_cost: '1500.00', net_profit: '19500.00',
      revenue_pos: '30000.00', revenue_preorder: '5000.00',
      cost_of_goods_pos: '12000.00', cost_of_goods_preorder: '2000.00',
      gross_profit_pos: '18000.00', gross_profit_preorder: '3000.00',
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Modal & Untung' }));
    expect(await screen.findByText('POS Rp 30.000 · Pre-order Rp 5.000')).toBeInTheDocument();
    expect(screen.getByText('POS Rp 12.000 · Pre-order Rp 2.000')).toBeInTheDocument();
    expect(screen.getByText('POS Rp 18.000 · Pre-order Rp 3.000')).toBeInTheDocument();
    // tepat tiga sub-baris: biaya event dan laba bersih milik seluruh event, tidak dipisah
    expect(screen.getAllByText(/^POS Rp/)).toHaveLength(3);
  });

  it('Modal & Untung: respons lama tanpa rincian tidak menampilkan sub-baris', async () => {
    profitReport.mockResolvedValue({
      event: { id: 1 }, revenue: '35000.00', cost_of_goods: '14000.00', gross_profit: '21000.00', event_cost: '0.00', net_profit: '21000.00',
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Modal & Untung' }));
    expect(await screen.findByText('Rp 35.000')).toBeInTheDocument();
    expect(screen.queryByText(/^POS Rp/)).not.toBeInTheDocument();
  });

  it('Modal Outlet: sel dan Grand Total memuat sub-baris yang jumlahnya sama dengan total', async () => {
    outletProfitReport.mockResolvedValue({
      event: { id: 1 },
      data: [
        { outlet_id: 5, outlet_name: 'Outlet A', total_sales: '35000.00', modal: '14000.00', gross_profit: '21000.00',
          sales_pos: '30000.00', sales_preorder: '5000.00', modal_pos: '12000.00', modal_preorder: '2000.00', gross_profit_pos: '18000.00', gross_profit_preorder: '3000.00' },
        { outlet_id: 6, outlet_name: 'Outlet B', total_sales: '10000.00', modal: '4000.00', gross_profit: '6000.00',
          sales_pos: '0.00', sales_preorder: '10000.00', modal_pos: '0.00', modal_preorder: '4000.00', gross_profit_pos: '0.00', gross_profit_preorder: '6000.00' },
      ],
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Modal Outlet' }));
    await screen.findByText('Outlet A');
    // baris
    expect(screen.getByText('POS Rp 30.000 · Pre-order Rp 5.000')).toBeInTheDocument();
    expect(screen.getByText('POS Rp 0 · Pre-order Rp 10.000')).toBeInTheDocument();
    // Grand Total: 30.000 + 0 | 5.000 + 10.000 -> total 45.000
    expect(screen.getByText('POS Rp 30.000 · Pre-order Rp 15.000')).toBeInTheDocument();
    expect(screen.getByText('Rp 45.000')).toBeInTheDocument();
    // catatan bahwa laporan ini kini memuat pre-order
    expect(screen.getByText(/juga menghitung bagian pre-order/i)).toBeInTheDocument();
  });
});
