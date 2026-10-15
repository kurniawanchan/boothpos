import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PurchaseOrderDetailModal from '../../resources/js/components/purchaseOrders/PurchaseOrderDetailModal.vue';
import { getPurchaseOrder } from '../../resources/js/api/purchaseOrders';
import { listArtists } from '../../resources/js/api/artists';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/purchaseOrders', () => ({
  getPurchaseOrder: vi.fn(),
  updatePurchaseOrder: vi.fn(),
  recordPurchaseOrderPayment: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));

const OUTDATED = new ApiError('Database perlu diperbarui. Minta administrator menerapkan pembaruan yang tertunda, lalu coba lagi.', { status: 503, code: 'schema_outdated' });
const PO = { id: 7, po_number: 'PO-7', vendor_name: 'Vendor X', artist_id: 1, artist_name: 'Seller A', status: 'ordered', total_amount: '5000.00', paid_amount: '0.00', items: [], payments: [], created_at: '2026-09-01T10:00:00+00:00' };

function renderModal() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(PurchaseOrderDetailModal, { props: { open: true, purchaseOrderId: 7 }, global: { plugins: [pinia] } });
}

// 035-po-row-actions (US4) — gagal memuat detail tidak boleh meninggalkan dialog kosong.
describe('PurchaseOrderDetailModal — load failure (035)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: [] });
  });

  it('shows the friendly error with a Retry button instead of a blank dialog', async () => {
    getPurchaseOrder.mockRejectedValueOnce(OUTDATED);
    renderModal();

    expect(await screen.findByRole('alert')).toHaveTextContent('Database perlu diperbarui');
    expect(screen.getByRole('button', { name: 'Coba lagi' })).toBeInTheDocument();
  });

  it('loads the purchase order after Retry once the problem is fixed', async () => {
    getPurchaseOrder.mockRejectedValueOnce(OUTDATED).mockResolvedValueOnce(PO);
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    await user.click(await screen.findByRole('button', { name: 'Coba lagi' }));

    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    expect(getPurchaseOrder).toHaveBeenCalledTimes(2);
    expect(await screen.findByText('Seller A')).toBeInTheDocument();
  });
});
