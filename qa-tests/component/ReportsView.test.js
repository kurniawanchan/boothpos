import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { artistSettlements, artistSettlementTransactions, artistProfitReport } from '../../resources/js/api/reports';
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
    await screen.findByRole('combobox'); // event selector — always rendered regardless of role
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
    expect(screen.getByText('Rp 60.000')).toBeInTheDocument();
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
    await screen.findByRole('combobox'); // event selector — always rendered regardless of role
    expect(screen.queryByText('Modal Penjual')).not.toBeInTheDocument();
  });
});
