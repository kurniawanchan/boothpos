import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { salesReport, artistSettlements, artistSettlementTransactions, artistProfitReport } from '../../resources/js/api/reports';
import { getProduct } from '../../resources/js/api/products';
import { getReceipt } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  salesReport: vi.fn(),
  artistSettlements: vi.fn(),
  artistSettlementTransactions: vi.fn(),
  profitReport: vi.fn(),
  artistProfitReport: vi.fn(),
  recordSettlementPayment: vi.fn(),
  exportReport: vi.fn(),
}));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getReceipt: vi.fn() }));

// This is the actual regression case from the bug report: "Transaksi: 3
// tapi tabel cuma ada 2 baris" — two of three orders bought the same
// product, so the per-product aggregate legitimately has 2 rows while
// there really are 3 transactions. The fix surfaces both, side by side.
const SALES_RESPONSE = {
  event: { id: 1, name: 'Event A' },
  group_by: 'product',
  group_label: 'Produk',
  totals: { order_count: 3, unit_count: 5, gross_sales: '150000.00', net_sales: '150000.00' },
  rows: [
    { entity_id: 10, label: 'Stiker Holografik', unit_count: 3, amount: '90000.00' },
    { entity_id: 11, label: 'Pin Akrilik', unit_count: 2, amount: '60000.00' },
  ],
  transactions: [
    { id: 101, order_number: 'ORD-001', customer_name: 'Budi Santoso', created_at: '2026-09-01T10:00:00Z', cashier_name: 'Kasir A', item_count: 2, total_amount: '60000.00' },
    { id: 102, order_number: 'ORD-002', customer_name: null, created_at: '2026-09-01T11:00:00Z', cashier_name: 'Kasir A', item_count: 1, total_amount: '30000.00' },
    { id: 103, order_number: 'ORD-003', customer_name: 'Siti Aminah', created_at: '2026-09-01T12:00:00Z', cashier_name: 'Kasir B', item_count: 2, total_amount: '60000.00' },
  ],
};

function renderReports() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'owner', name: 'Owner' };
  return render(ReportsView, { global: { plugins: [pinia] } });
}

describe('ReportsView — sales tab', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES_RESPONSE);
  });

  it('uses the server-supplied group_label instead of a hardcoded "Label" header', async () => {
    renderReports();
    expect(await screen.findByText('Produk')).toBeInTheDocument();
    expect(screen.queryByText('Label')).not.toBeInTheDocument();
  });

  it('renders all real transactions even when the per-product breakdown has fewer rows', async () => {
    renderReports();
    await screen.findByText('Stiker Holografik');
    expect(screen.getByText(/daftar transaksi \(3\)/i)).toBeInTheDocument();
    expect(screen.getByText('ORD-001')).toBeInTheDocument();
    expect(screen.getByText('ORD-002')).toBeInTheDocument();
    expect(screen.getByText('ORD-003')).toBeInTheDocument();
  });

  it('opens the product detail view when a product-grouped row label is clicked', async () => {
    getProduct.mockResolvedValue({
      id: 10,
      name: 'Stiker Holografik',
      code_prefix: 'ABCST',
      artist_name: 'Artist A',
      category_name: 'Stiker',
      is_preorder: false,
      is_active: true,
      description: '',
      variants: [{ id: 1, sku: 'ABCST0001', variant_name: 'Standard', sell_price: '30000.00', current_stock: 12, is_active: true }],
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    const link = await screen.findByRole('button', { name: 'Stiker Holografik' });
    await user.click(link);
    await waitFor(() => expect(getProduct).toHaveBeenCalledWith(10));
    expect(await screen.findByText('Total stok tersedia (semua varian)')).toBeInTheDocument();
    expect((await screen.findAllByText('12')).length).toBeGreaterThan(0);
  });

  it('opens the receipt for a transaction row via "Lihat struk"', async () => {
    getReceipt.mockResolvedValue({
      order_number: 'ORD-001',
      store_name: 'Toko A',
      event_name: 'Event A',
      created_at: '2026-09-01T10:00:00Z',
      cashier_name: 'Kasir A',
      items: [],
      subtotal: '60000.00',
      discount_amount: '0.00',
      total_amount: '60000.00',
      payment_summary: [],
      change_amount: '0.00',
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    const btn = await screen.findAllByRole('button', { name: /lihat struk/i });
    await user.click(btn[0]);
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(101));
    expect((await screen.findAllByText('ORD-001')).length).toBeGreaterThan(0);
    expect(screen.getByText('Toko A')).toBeInTheDocument();
  });
});

// F10.6 — client-side search over the already-loaded transactions[] array.
describe('ReportsView — sales tab transaction search (F10.6)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES_RESPONSE);
  });

  it('filters by order number, case-insensitively', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'ord-002');
    expect(screen.queryByText('ORD-001')).not.toBeInTheDocument();
    expect(screen.getByText('ORD-002')).toBeInTheDocument();
    expect(screen.queryByText('ORD-003')).not.toBeInTheDocument();
  });

  it('filters by customer name and gracefully skips walk-in (null customer_name) rows instead of erroring', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'budi');
    expect(screen.getByText('ORD-001')).toBeInTheDocument();
    expect(screen.queryByText('ORD-002')).not.toBeInTheDocument();
    expect(screen.queryByText('ORD-003')).not.toBeInTheDocument();
  });

  it('filters by cashier name', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'kasir b');
    expect(screen.queryByText('ORD-001')).not.toBeInTheDocument();
    expect(screen.getByText('ORD-003')).toBeInTheDocument();
  });

  it('shows a "no match" empty message and does not throw for a null-customer row when searching', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'nomatch-xyz');
    expect(screen.getByText(/tidak ada transaksi yang cocok/i)).toBeInTheDocument();
  });
});

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
        order_id: 201,
        order_number: 'ORD-201',
        created_at: '2026-09-01T09:00:00Z',
        items: [{ sku: 'ABCST0001', name: 'Stiker Holografik', qty: 3, line_total: '90000.00' }],
        order_total_for_artist: '90000.00',
      },
    ],
  };

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES_RESPONSE);
    artistSettlements.mockResolvedValue({ data: SETTLEMENT_ROWS });
    artistSettlementTransactions.mockResolvedValue(DRILLDOWN_RESPONSE);
  });

  it('opens the drill-down modal and renders only this artist\'s isolated order items, trusting the backend filter as-is', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await user.click(screen.getByRole('button', { name: 'Rekap Artist' }));
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
    auth.user = { id: 2, role: 'cashier', name: 'Kasir' };
    render(ReportsView, { global: { plugins: [pinia] } });
    await screen.findByText('Penjualan');
    expect(screen.queryByText('Rekap Artist')).not.toBeInTheDocument();
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
    salesReport.mockResolvedValue(SALES_RESPONSE);
    artistProfitReport.mockResolvedValue(ARTIST_PROFIT_RESPONSE);
  });

  it('is visible for owner/admin and renders real per-artist gross-profit figures', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('Penjualan');
    const tab = screen.getByRole('button', { name: 'Modal Artist' });
    await user.click(tab);
    await waitFor(() => expect(artistProfitReport).toHaveBeenCalledWith(1));
    expect(await screen.findByText('Artist A')).toBeInTheDocument();
    expect(screen.getByText('Rp 60.000')).toBeInTheDocument();
  });

  it('shows a note that the figure excludes event_cost, not a net-profit figure', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('Penjualan');
    await user.click(screen.getByRole('button', { name: 'Modal Artist' }));
    expect(await screen.findByText(/belum dikurangi biaya event/i)).toBeInTheDocument();
  });

  it('is hidden entirely for cashier/inventory roles', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore();
    auth.user = { id: 3, role: 'inventory', name: 'Gudang' };
    render(ReportsView, { global: { plugins: [pinia] } });
    await screen.findByText('Penjualan');
    expect(screen.queryByText('Modal Artist')).not.toBeInTheDocument();
  });
});
