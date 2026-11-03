import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TablesAdmin from '../../resources/js/components/restaurant/TablesAdmin.vue';
import { listDiningTables, qrBatch, rotateTableQr } from '../../resources/js/api/diningTables';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/diningTables', () => ({
  listDiningTables: vi.fn(), createDiningTable: vi.fn(), updateDiningTable: vi.fn(), deleteDiningTable: vi.fn(), qrBatch: vi.fn(), rotateTableQr: vi.fn(),
}));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async (url) => `data:image/png;base64,${btoa(url)}`) } }));

const row = (over) => ({ id: 1, name: '7', area: 'Indoor', seats: 4, display_order: 0, is_active: true, has_open_tab: false, qr_ready: true, ...over });
const qrItem = (over) => ({ table_id: 1, outlet_name: 'Warkop WJM 3', table_name: '7', area: 'Indoor', url: 'http://192.168.1.10:8010/pesan/abc.def', ...over });

function renderAdmin() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(TablesAdmin, { global: { plugins: [pinia] } });
}

describe('TablesAdmin — QR pesan mandiri', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listDiningTables.mockResolvedValue({ data: [row(), row({ id: 2, name: 'Bar', is_active: false })] });
  });

  it('offers "Buat QR" only on active tables', async () => {
    renderAdmin();
    await screen.findByText('Bar');

    expect(screen.getAllByRole('button', { name: /^Buat QR meja/ })).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Buat QR meja 7' })).toBeInTheDocument();
  });

  it('asks the server for that one table, without the top-bar outlet header, and shows the QR', async () => {
    qrBatch.mockResolvedValue({ items: [qrItem()], skipped: { inactive: 0 } });
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Bar');

    await user.click(screen.getByRole('button', { name: 'Buat QR meja 7' }));

    expect(qrBatch).toHaveBeenCalledWith({ ids: [1] }, { skipOutletHeader: true });
    expect(await screen.findByAltText('Kode QR meja 7')).toBeInTheDocument();
    expect(screen.getByText('Warkop WJM 3')).toBeInTheDocument();
  });

  it('remakes the QR only after confirmation and then shows the new address', async () => {
    qrBatch.mockResolvedValue({ items: [qrItem()], skipped: { inactive: 0 } });
    rotateTableQr.mockResolvedValue({ table_id: 1, url: 'http://192.168.1.10:8010/pesan/baru.token' });
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Bar');
    await user.click(screen.getByRole('button', { name: 'Buat QR meja 7' }));
    await screen.findByAltText('Kode QR meja 7');

    await user.click(screen.getByRole('button', { name: 'Buat ulang QR' }));
    expect(rotateTableQr).not.toHaveBeenCalled();
    expect(await screen.findByText(/QR lama meja 7 akan berhenti berlaku/)).toBeInTheDocument();

    const confirm = screen.getAllByRole('button', { name: 'Buat ulang QR' }).at(-1);
    await user.click(confirm);

    await waitFor(() => expect(rotateTableQr).toHaveBeenCalledWith(1, { skipOutletHeader: true }));
  });

  it('explains how to fix it when the base address is not ready', async () => {
    listDiningTables.mockResolvedValue({ data: [row({ qr_ready: false }), row({ id: 3, name: '8', qr_ready: false })] });
    renderAdmin();

    expect(await screen.findByTestId('qr-not-ready')).toHaveTextContent('Pengaturan → Self-Service');
  });

  it('does not nag when at least one row says QR is ready', async () => {
    renderAdmin();
    await screen.findByText('Bar');

    expect(screen.queryByTestId('qr-not-ready')).not.toBeInTheDocument();
  });

  it('keeps the list usable when the server refuses (409 is toasted centrally)', async () => {
    qrBatch.mockRejectedValue(new ApiError('Alamat dasar belum diatur.', { status: 409, code: 'qr_base_url_invalid' }));
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Bar');

    await user.click(screen.getByRole('button', { name: 'Buat QR meja 7' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Buat QR meja 7' })).toBeEnabled());
    expect(screen.queryByAltText('Kode QR meja 7')).not.toBeInTheDocument();
  });
});
