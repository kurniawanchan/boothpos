import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { listArtists } from '../../resources/js/api/artists';
import { artistSettlements, stockByArtistReport, exportReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  artistSettlements: vi.fn(),
  artistSettlementTransactions: vi.fn(),
  profitReport: vi.fn(),
  artistProfitReport: vi.fn(),
  purchasesReport: vi.fn(),
  stockByArtistReport: vi.fn(),
  preorderReport: vi.fn(),
  exportReport: vi.fn(),
}));

const ROWS = [
  { id: 1, artist_id: 5, artist_name: 'Artist A', total_units: 3, total_sales: '30000.00' },
  { id: null, artist_id: 6, artist_name: 'Artist B', total_units: 0, total_sales: '0.00' },
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
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    listArtists.mockResolvedValue({ data: [] });
    artistSettlements.mockResolvedValue({ data: ROWS });
  });

  it('renders the page header (single h1) with the export action and a tablist with all six report tabs', async () => {
    renderReports();
    await screen.findByText('Artist A');

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1, name: 'Laporan' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ekspor \.xlsx/i })).toBeInTheDocument();

    const tabs = within(screen.getByRole('tablist')).getAllByRole('tab');
    expect(tabs).toHaveLength(6);
    expect(tabs[0]).toHaveAttribute('aria-selected', 'true');
  });

  it('the "N data" counter equals the rows actually shown', async () => {
    renderReports();
    await screen.findByText('Artist A');
    expect(screen.getByTestId('data-count')).toHaveTextContent('2 data');
    expect(document.querySelectorAll('tbody tr')).toHaveLength(2);
  });

  it('exports the active tab with the server report name', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    await screen.findByText('Artist A');
    await user.click(screen.getByRole('button', { name: /ekspor \.xlsx/i }));
    expect(exportReport).toHaveBeenCalledWith('artist-settlements', { event_id: 1 });
  });

  it('shows an error state with a retry button that reloads the tab', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    artistSettlements.mockRejectedValueOnce(new Error('network'));
    renderReports();

    expect(await screen.findByRole('alert')).toHaveTextContent(/gagal memuat/i);
    expect(screen.queryByTestId('data-count')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Artist A')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('hides the export action and tabs for roles without reports access', async () => {
    renderReports(['dashboard', 'pos']);
    await screen.findAllByRole('combobox');
    expect(screen.queryByRole('button', { name: /ekspor \.xlsx/i })).not.toBeInTheDocument();
    expect(screen.queryAllByRole('tab')).toHaveLength(0);
  });

  it('stock-by-seller tab counts the rows shown', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    stockByArtistReport.mockResolvedValue({ data: [{ artist_id: 5, artist_name: 'Artist A', variant_count: 2, total_stock: 10 }] });
    renderReports();
    await user.click(await screen.findByRole('tab', { name: 'Stok per Penjual' }));
    await screen.findByText('Artist A');
    await waitFor(() => expect(screen.getByTestId('data-count')).toHaveTextContent('1 data'));
  });
});
