import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import VariantHistoryModal from '../../resources/js/components/product/VariantHistoryModal.vue';
import { listMovements } from '../../resources/js/api/stock';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/stock', () => ({ listMovements: vi.fn() }));

const ROW = (id, extra = {}) => ({
  id, variant_id: 42, sku: 'SPF-KC-MCY-008', type: 'sale', qty_change: -1, stock_before: 9, stock_after: 8,
  reason: null, created_at: '2026-10-04T06:29:00+00:00', user_name: 'Chan', variant_name: 'Slippery 5cm',
  product_id: 2, product_name: 'MCYT', reference: null, ...extra,
});
const META = (extra = {}) => ({ current_page: 1, per_page: 25, total: 3, last_page: 1, ...extra });
const PAGE = {
  data: [
    ROW(3, { reference: { type: 'order', id: 41, number: 'TRX-20261004-0007' } }),
    ROW(2, { type: 'adjustment', qty_change: 9, stock_before: 0, stock_after: 9, reason: 'add slippery 5cm', user_name: 'Rina' }),
    ROW(1, { type: 'preorder_handover', qty_change: -2, stock_before: 11, stock_after: 9, reference: { type: 'preorder', id: 5, number: 'PRE-0005' } }),
  ],
  meta: META(),
};

function renderModal() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(VariantHistoryModal, { props: { open: true, variantId: 42, variantSku: 'SPF-KC-MCY-008', variantName: 'Slippery 5cm' }, global: { plugins: [pinia] } });
}

// 036-bom-variant-stock-ux (US3) — riwayat transaksi satu varian (hanya-baca).
describe('VariantHistoryModal (036)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMovements.mockResolvedValue(PAGE);
  });

  it('loads only this variant, newest first as the server returns it, with every column', async () => {
    renderModal();

    expect(await screen.findByText('TRX-20261004-0007')).toBeInTheDocument();
    expect(listMovements).toHaveBeenCalledWith(expect.objectContaining({ variant_id: 42, page: 1 }));
    for (const header of ['Tipe', 'Perubahan', 'Sebelum → Sesudah', 'Referensi / alasan', 'Oleh', 'Waktu']) {
      expect(screen.getByRole('columnheader', { name: header })).toBeInTheDocument();
    }
    expect(screen.getByText('9 → 8')).toBeInTheDocument();
    expect(screen.getByText('+9')).toBeInTheDocument();
    expect(screen.getByText('-1')).toBeInTheDocument();
    expect(screen.getByText('Penjualan')).toBeInTheDocument();
    expect(screen.getByText('Penyesuaian')).toBeInTheDocument();
    expect(screen.getByText('add slippery 5cm')).toBeInTheDocument();
    expect(screen.getByText('PRE-0005')).toBeInTheDocument();
    expect(screen.getByText('Rina')).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/master_data\./i);
  });

  it('shows the SKU in the title', async () => {
    renderModal();

    expect(await screen.findByRole('dialog', { name: /riwayat transaksi — SPF-KC-MCY-008/i })).toBeInTheDocument();
  });

  it('re-queries from page 1 when the type filter changes', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('TRX-20261004-0007');

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Penyesuaian' }));

    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ variant_id: 42, type: 'adjustment', page: 1 })));
  });

  it('re-queries with the date range', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('TRX-20261004-0007');

    await user.type(screen.getByLabelText('Dari tanggal'), '2026-10-01');
    await user.type(screen.getByLabelText('Sampai tanggal'), '2026-10-05');

    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ date_from: '2026-10-01', date_to: '2026-10-05', variant_id: 42, page: 1 })));
  });

  it('pages through a long history', async () => {
    listMovements.mockResolvedValueOnce({ data: PAGE.data, meta: META({ total: 60, last_page: 3 }) });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('TRX-20261004-0007');

    await user.click(screen.getByRole('button', { name: /halaman berikutnya|next/i }));

    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, variant_id: 42 })));
  });

  it('shows an empty state (not a blank dialog) when the variant has no movements', async () => {
    listMovements.mockResolvedValue({ data: [], meta: META({ total: 0 }) });
    renderModal();

    expect(await screen.findByText('Belum ada pergerakan stok untuk varian ini.')).toBeInTheDocument();
  });

  it('shows the error with Retry when loading fails, and Retry loads it', async () => {
    listMovements.mockRejectedValueOnce(new ApiError('Database perlu diperbarui.', { status: 503, code: 'schema_outdated' }));
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    expect(await screen.findByRole('alert')).toHaveTextContent('Database perlu diperbarui');
    expect(screen.queryByText('Belum ada pergerakan stok untuk varian ini.')).not.toBeInTheDocument();

    await user.click(within(screen.getByRole('alert')).getByRole('button', { name: 'Coba lagi' }));

    expect(await screen.findByText('TRX-20261004-0007')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});
