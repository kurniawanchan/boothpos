import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { artistSettlements, artistSettlementTransactions, artistProfitReport, profitReport } from '../../resources/js/api/reports';
import { listArtists } from '../../resources/js/api/artists';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  artistSettlements: vi.fn(),
  artistSettlementTransactions: vi.fn(),
  profitReport: vi.fn(),
  artistProfitReport: vi.fn(),
  purchasesReport: vi.fn(),
  stockByArtistReport: vi.fn(),
  recordSettlementPayment: vi.fn(),
  exportReport: vi.fn(),
}));

function renderReports() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'reports'] };
  return render(ReportsView, { global: { plugins: [pinia] } });
}

// F11.6 — drill-down transaction detail from the Rekap Artist tab.
describe('ReportsView — artist transaction drill-down (F11.6)', () => {
  const SETTLEMENT_ROWS = [
    { id: 1, artist_id: 5, artist_name: 'Artist A', total_units: 3, total_sales: '90000.00', payable_amount: '90000.00', paid_amount: '0.00', outstanding: '90000.00', status: 'unpaid' },
  ];

  const DRILLDOWN_RESPONSE = {
    event: { id: 1, name: 'Event A' },
    artist: { id: 5, name: 'Artist A' },
    transactions: [
      {
        key: 'order-201',
        number: 'ORD-201',
        source: 'order',
        created_at: '2026-09-01T09:00:00Z',
        items: [{ sku: 'ABCST0001', name: 'Stiker Holografik', qty: 3, line_total: '90000.00' }],
        amount_for_artist: '90000.00',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    listArtists.mockResolvedValue({ data: [] });
    artistSettlements.mockResolvedValue({ data: SETTLEMENT_ROWS });
    artistSettlementTransactions.mockResolvedValue(DRILLDOWN_RESPONSE);
  });

  it('opens the drill-down modal and renders only this artist\'s isolated order items, trusting the backend filter as-is', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    // "Rekap Artist" is now the default active tab (Penjualan moved out
    // to its own page), so its data loads on mount without a click.
    await screen.findByText('Artist A');
    await user.click(screen.getByRole('button', { name: 'Detail transaksi' }));
    await waitFor(() => expect(artistSettlementTransactions).toHaveBeenCalledWith(5, 1));
    expect(await screen.findByText('ORD-201')).toBeInTheDocument();
    expect(screen.getByText('Stiker Holografik')).toBeInTheDocument();
    // Only 1 item row rendered — the response already isolated this
    // artist's line items, so the modal must not re-filter or duplicate.
    expect(screen.getAllByText('ABCST0001')).toHaveLength(1);
  });

  it('is hidden for non-owner/admin roles because the Rekap Artist tab itself is hidden for them', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore();
    auth.user = { id: 2, role: 'Kasir', name: 'Kasir', menu_keys: ['dashboard', 'pos'] };
    render(ReportsView, { global: { plugins: [pinia] } });
    await screen.findAllByRole('combobox'); // event selector (+ seller filter) — always rendered regardless of role
    expect(screen.queryByText('Rekap Penjual')).not.toBeInTheDocument();
  });
});

// F9.5 — per-artist gross profit view, deliberately excluding event_cost.
describe('ReportsView — artist profit tab (F9.5)', () => {
  const ARTIST_PROFIT_RESPONSE = {
    event: { id: 1, name: 'Event A' },
    data: [
      { artist_id: 5, artist_name: 'Artist A', total_sales: '90000.00', modal: '30000.00', gross_profit: '60000.00' },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    listArtists.mockResolvedValue({ data: [] });
    artistSettlements.mockResolvedValue({ data: [] });
    artistProfitReport.mockResolvedValue(ARTIST_PROFIT_RESPONSE);
  });

  it('is visible for owner/admin and renders real per-artist gross-profit figures', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    const tab = await screen.findByRole('button', { name: 'Modal Penjual' });
    await user.click(tab);
    await waitFor(() => expect(artistProfitReport).toHaveBeenCalledWith(1));
    expect(await screen.findByText('Artist A')).toBeInTheDocument();
    // Rp 60.000 appears twice with only one row: once in the data row, once
    // in the new Grand Total footer row (which mirrors the single row's own
    // total when there's nothing else to sum).
    expect(screen.getAllByText('Rp 60.000')).toHaveLength(2);
  });

  it('shows a note that the figure excludes event_cost, not a net-profit figure', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('button', { name: 'Modal Penjual' }));
    expect(await screen.findByText(/belum dikurangi biaya event/i)).toBeInTheDocument();
  });

  it('is hidden entirely for cashier/inventory roles', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore();
    auth.user = { id: 3, role: 'Inventory', name: 'Gudang', menu_keys: ['dashboard', 'products', 'stock'] };
    render(ReportsView, { global: { plugins: [pinia] } });
    await screen.findAllByRole('combobox'); // event selector (+ seller filter) — always rendered regardless of role
    expect(screen.queryByText('Modal Penjual')).not.toBeInTheDocument();
  });
});

// 033 — Rekap Seller memisahkan POS vs pre-order; keduanya selalu menjumlah
// ke Unit/Penjualan di baris yang sama, dan Grand Total ikut dipisah.
describe('ReportsView — Rekap Seller POS vs pre-order (033)', () => {
  const SPLIT_ROWS = [
    { id: 1, artist_id: 5, artist_name: 'Artist A', total_units: 4, pos_units: 3, preorder_units: 1, total_sales: '35000.00', pos_sales: '30000.00', preorder_sales: '5000.00', deduction: '0.00', payable_amount: '35000.00', paid_amount: '0.00', outstanding: '35000.00', status: 'unpaid' },
    { id: null, artist_id: 6, artist_name: 'Artist B', total_units: 2, pos_units: 0, preorder_units: 2, total_sales: '20000.00', pos_sales: '0.00', preorder_sales: '20000.00', deduction: '0.00', payable_amount: '0.00', paid_amount: '0.00', outstanding: '0.00', status: 'unpaid' },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    listArtists.mockResolvedValue({ data: [] });
  });

  it('shows the POS and pre-order unit/sales columns and a Grand Total row that reconciles', async () => {
    artistSettlements.mockResolvedValue({ data: SPLIT_ROWS });
    renderReports();
    await screen.findByText('Artist A');

    for (const label of ['Unit POS', 'Unit pre-order', 'Penjualan POS', 'Penjualan pre-order']) {
      expect(screen.getByRole('columnheader', { name: label })).toBeInTheDocument();
    }

    const grand = screen.getByText('Grand Total').closest('tr');
    const cells = [...grand.querySelectorAll('td')].map((td) => td.textContent.replace(/\s+/g, ' ').trim());
    // [label, pos unit, pre-order unit, unit, pos sales, pre-order sales, sales, ...]
    expect(cells[1]).toBe('3');
    expect(cells[2]).toBe('3');
    expect(cells[3]).toBe('6'); // 3 + 3 == total units
    expect(cells[4]).toMatch(/30\.000/);
    expect(cells[5]).toMatch(/25\.000/);
    expect(cells[6]).toMatch(/55\.000/); // 30.000 + 25.000 == total sales
  });

  it('still renders an old response without the split fields (shows a dash, not a misleading zero)', async () => {
    artistSettlements.mockResolvedValue({
      data: [{ id: 1, artist_id: 5, artist_name: 'Artist A', total_units: 3, total_sales: '90000.00', payable_amount: '90000.00', paid_amount: '0.00', outstanding: '90000.00', status: 'unpaid' }],
    });
    renderReports();
    await screen.findByText('Artist A');
    expect(screen.getAllByText('–').length).toBeGreaterThanOrEqual(4);
  });
});

// 033 — Modal & Untung dan Modal Penjual: total + rincian POS/pre-order.
describe('ReportsView — POS vs pre-order di laporan modal (033)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    listArtists.mockResolvedValue({ data: [] });
    artistSettlements.mockResolvedValue({ data: [] });
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
    await user.click(await screen.findByRole('button', { name: 'Modal & Untung' }));
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
    await user.click(await screen.findByRole('button', { name: 'Modal & Untung' }));
    expect(await screen.findByText('Rp 35.000')).toBeInTheDocument();
    expect(screen.queryByText(/^POS Rp/)).not.toBeInTheDocument();
  });

  it('Modal Penjual: sel dan Grand Total memuat sub-baris yang jumlahnya sama dengan total', async () => {
    artistProfitReport.mockResolvedValue({
      event: { id: 1 },
      data: [
        { artist_id: 5, artist_name: 'Artist A', total_sales: '35000.00', modal: '14000.00', gross_profit: '21000.00',
          sales_pos: '30000.00', sales_preorder: '5000.00', modal_pos: '12000.00', modal_preorder: '2000.00', gross_profit_pos: '18000.00', gross_profit_preorder: '3000.00' },
        { artist_id: 6, artist_name: 'Artist B', total_sales: '10000.00', modal: '4000.00', gross_profit: '6000.00',
          sales_pos: '0.00', sales_preorder: '10000.00', modal_pos: '0.00', modal_preorder: '4000.00', gross_profit_pos: '0.00', gross_profit_preorder: '6000.00' },
      ],
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(await screen.findByRole('button', { name: 'Modal Penjual' }));
    await screen.findByText('Artist A');
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
