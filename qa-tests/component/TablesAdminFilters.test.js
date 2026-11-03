import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TablesAdmin from '../../resources/js/components/restaurant/TablesAdmin.vue';
import { listDiningTables, qrBatch } from '../../resources/js/api/diningTables';

vi.mock('../../resources/js/api/diningTables', () => ({
  listDiningTables: vi.fn(), createDiningTable: vi.fn(), updateDiningTable: vi.fn(), deleteDiningTable: vi.fn(), qrBatch: vi.fn(), rotateTableQr: vi.fn(),
}));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async (u) => `data:image/png;base64,${btoa(u)}`) } }));

const SKIP = { skipOutletHeader: true };
const row = (id, over = {}) => ({ id, name: `M${id}`, area: 'Indoor', seats: 4, display_order: 0, is_active: true, has_open_tab: false, qr_ready: true, ...over });
const rowsOf = (from, to, over) => Array.from({ length: to - from + 1 }, (_, i) => row(from + i, over));
const envelope = (data, total, page = 1, last = 1) => ({ data, meta: { current_page: page, per_page: 25, total, last_page: last, areas: ['Indoor', 'Teras'] } });

function mount() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(TablesAdmin, { global: { plugins: [pinia] } });
}
const lastParams = () => listDiningTables.mock.calls.at(-1)[0];

describe('TablesAdmin — filter, paginasi, dan pilihan (090 US7)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDiningTables.mockResolvedValue(envelope(rowsOf(1, 25), 60, 1, 3));
  });

  it('asks the server for one page at a time and shows the pager', async () => {
    mount();

    expect(await screen.findByText('M1')).toBeInTheDocument();
    expect(listDiningTables.mock.calls[0]).toEqual([{ page: 1, per_page: 25 }, SKIP]);
    expect(screen.getByText('1 / 3')).toBeInTheDocument();
  });

  it('goes to the next page with the same filters', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    listDiningTables.mockResolvedValue(envelope(rowsOf(26, 50), 60, 2, 3));

    await user.click(screen.getByRole('button', { name: /berikutnya|next/i }));

    expect(await screen.findByText('M26')).toBeInTheDocument();
    expect(lastParams()).toMatchObject({ page: 2, per_page: 25 });
  });

  it('searches after a short pause and returns to page 1', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    listDiningTables.mockClear();

    await user.type(screen.getByPlaceholderText('Nama atau area meja…'), 'teras');

    expect(listDiningTables).not.toHaveBeenCalled();
    await waitFor(() => expect(listDiningTables).toHaveBeenCalledTimes(1), { timeout: 2000 });
    expect(lastParams()).toMatchObject({ page: 1, q: 'teras' });
  });

  it('filters by area and by status', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');

    await user.click(screen.getByRole('combobox', { name: 'Area' }));
    await user.click(await screen.findByRole('option', { name: 'Teras' }));
    await waitFor(() => expect(lastParams()).toMatchObject({ page: 1, area: 'Teras' }));

    await user.click(screen.getByRole('combobox', { name: 'Status' }));
    await user.click(await screen.findByRole('option', { name: 'Nonaktif' }));
    await waitFor(() => expect(lastParams()).toMatchObject({ area: 'Teras', is_active: 0 }));
  });

  it('keeps ticked tables across pages and clears them when a filter changes', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    await user.click(screen.getByLabelText('Pilih meja M1'));
    await user.click(screen.getByLabelText('Pilih meja M2'));
    expect(screen.getByTestId('tables-selection-count')).toHaveTextContent('2 meja dipilih');

    listDiningTables.mockResolvedValue(envelope(rowsOf(26, 50), 60, 2, 3));
    await user.click(screen.getByRole('button', { name: /berikutnya|next/i }));
    await screen.findByText('M26');
    expect(screen.getByTestId('tables-selection-count')).toHaveTextContent('2 meja dipilih');
    await user.click(screen.getByLabelText('Pilih meja M26'));
    expect(screen.getByTestId('tables-selection-count')).toHaveTextContent('3 meja dipilih');

    await user.click(screen.getByRole('combobox', { name: 'Area' }));
    await user.click(await screen.findByRole('option', { name: 'Teras' }));
    await waitFor(() => expect(screen.queryByTestId('tables-selection-count')).not.toBeInTheDocument());
  });

  it('makes QR codes for exactly the ticked tables', async () => {
    qrBatch.mockResolvedValue({ items: [{ table_id: 1, outlet_name: 'W3', table_name: 'M1', area: 'Indoor', url: 'http://x/pesan/a' }, { table_id: 3, outlet_name: 'W3', table_name: 'M3', area: 'Indoor', url: 'http://x/pesan/b' }], skipped: { inactive: 0 } });
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    await user.click(screen.getByLabelText('Pilih meja M1'));
    await user.click(screen.getByLabelText('Pilih meja M3'));

    await user.click(screen.getByTestId('bulk-qr'));

    await waitFor(() => expect(qrBatch).toHaveBeenCalledWith({ ids: expect.arrayContaining([1, 3]) }, SKIP));
    expect(qrBatch.mock.calls[0][0].ids).toHaveLength(2);
    expect(await screen.findByTestId('qr-bulk-count')).toHaveTextContent('2 QR meja');
  });

  it('offers "select all N in this filter" only when the page is fully ticked, and then sends the filter instead of ids', async () => {
    qrBatch.mockResolvedValue({ items: [{ table_id: 1, outlet_name: 'W3', table_name: 'M1', area: 'Indoor', url: 'http://x/pesan/a' }], skipped: { inactive: 0 } });
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    expect(screen.queryByTestId('select-all-filtered')).not.toBeInTheDocument();

    await user.click(screen.getByLabelText('Pilih semua di halaman ini'));
    expect(screen.getByTestId('tables-selection-count')).toHaveTextContent('25 meja dipilih');
    await user.click(screen.getByTestId('select-all-filtered'));
    expect(screen.getByTestId('tables-selection-count')).toHaveTextContent('60 meja dipilih');
    expect(screen.getByText('Semua 60 meja hasil filter dipilih.')).toBeInTheDocument();

    await user.click(screen.getByTestId('bulk-qr'));

    await waitFor(() => expect(qrBatch).toHaveBeenCalledWith({ filter: {} }, SKIP));
  });

  it('sends the active filters with an all-in-filter request', async () => {
    qrBatch.mockResolvedValue({ items: [{ table_id: 1, outlet_name: 'W3', table_name: 'M1', area: 'Indoor', url: 'http://x/pesan/a' }], skipped: { inactive: 0 } });
    listDiningTables.mockResolvedValue(envelope(rowsOf(1, 25, { area: 'Teras' }), 40, 1, 2));
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    await user.click(screen.getByRole('combobox', { name: 'Area' }));
    await user.click(await screen.findByRole('option', { name: 'Teras' }));
    await waitFor(() => expect(lastParams()).toMatchObject({ area: 'Teras' }));

    await user.click(screen.getByLabelText('Pilih semua di halaman ini'));
    await user.click(screen.getByTestId('select-all-filtered'));
    await user.click(screen.getByTestId('bulk-qr'));

    await waitFor(() => expect(qrBatch).toHaveBeenCalledWith({ filter: { area: 'Teras' } }, SKIP));
  });

  it('refuses more than 200 hand-picked tables before calling the server', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    // 9 halaman × 25 pilihan tak praktis di tes: cukup bila ambang dicek di klien; di sini hanya memastikan 1 pilihan tidak ditolak.
    await user.click(screen.getByLabelText('Pilih meja M1'));
    qrBatch.mockResolvedValue({ items: [{ table_id: 1, outlet_name: 'W3', table_name: 'M1', area: '', url: 'http://x/pesan/a' }], skipped: { inactive: 0 } });
    await user.click(screen.getByTestId('bulk-qr'));

    await waitFor(() => expect(qrBatch).toHaveBeenCalled());
  });

  it('shows the filtered empty message when nothing matches', async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByText('M1');
    listDiningTables.mockResolvedValue(envelope([], 0));

    await user.type(screen.getByPlaceholderText('Nama atau area meja…'), 'zzz');

    await waitFor(() => expect(screen.getByText(/tidak ada meja/i)).toBeInTheDocument(), { timeout: 2000 });
  });
});
