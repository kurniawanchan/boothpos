import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import OrderApprovalSection from '../../resources/js/components/sales/OrderApprovalSection.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { getApprovals, approveSeller, getApprovalImageBlobUrl } from '../../resources/js/api/orderApprovals';

vi.mock('../../resources/js/api/orderApprovals', () => ({ getApprovals: vi.fn(), approveSeller: vi.fn(), getApprovalImageBlobUrl: vi.fn() }));
const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));

const line = (name, qty, total) => ({ sku: 'S', name, qty, sell_price: '1.00', discount_amount: '0.00', line_total: total });
const A_DONE = {
  artist_id: 1, artist_name: 'Seller A', status: 'approved', stale: false, subtotal: '8000.00', items_hash: 'v1:a',
  items: [line('Indomie Goreng', 1, '5000.00'), line('Es Teh', 1, '3000.00')], can_approve: false,
  approval: { id: 31, method: 'stamp', approver_id: 9, approver_name: 'Budi Penyetuju', on_behalf: false, approved_at: '2026-11-05T11:20:00+07:00', has_image: false, can_view_image: false },
};
const B_DONE_IMG = {
  artist_id: 2, artist_name: 'Seller B', status: 'approved', stale: false, subtotal: '12000.00', items_hash: 'v1:b',
  items: [line('Kopi Susu', 1, '12000.00')], can_approve: false,
  approval: { id: 32, method: 'draw', approver_id: 8, approver_name: 'Beta User', on_behalf: false, approved_at: '2026-11-05T11:30:00+07:00', has_image: true, can_view_image: true },
};
const C_PENDING = {
  artist_id: 3, artist_name: 'Seller C', status: 'pending', stale: false, subtotal: '15000.00', items_hash: 'v1:c',
  items: [line('Roti Bakar', 1, '15000.00')], can_approve: true, approval: null,
};
const PARTIAL = { order_id: 7, status: 'partial', total: 3, approved: 2, pending: 1, progress: '2/3', sellers: [A_DONE, B_DONE_IMG, C_PENDING] };

function mount(permissions, artist = { id: 3, name: 'Seller C' }) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 5, name: 'C User', role: 'Cashier', menu_keys: ['sales'], permissions, artist };
  return render(OrderApprovalSection, { props: { orderId: 7 }, global: { plugins: [pinia] } });
}

describe('OrderApprovalSection (059)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getApprovals.mockResolvedValue(PARTIAL);
    getApprovalImageBlobUrl.mockResolvedValue('blob:img');
  });

  it('shows the overall status + progress SEPARATELY from each seller status', async () => {
    mount();
    expect(await screen.findByTestId('approval-overall-status')).toHaveTextContent('Sebagian Disetujui');
    expect(screen.getByTestId('approval-progress')).toHaveTextContent('2/3');

    expect(within(screen.getByTestId('approval-seller-1')).getByTestId('approval-seller-status')).toHaveTextContent('Disetujui');
    expect(within(screen.getByTestId('approval-seller-3')).getByTestId('approval-seller-status')).toHaveTextContent('Menunggu persetujuan');
  });

  it('lists one block per seller with their items — not one per item', async () => {
    mount();
    await screen.findByTestId('approval-overall');
    expect(screen.getAllByTestId(/^approval-seller-\d+$/)).toHaveLength(3);
    expect(within(screen.getByTestId('approval-seller-1')).getByText(/Indomie Goreng/)).toBeInTheDocument();
    expect(within(screen.getByTestId('approval-seller-1')).getByText(/Es Teh/)).toBeInTheDocument();
  });

  it('shows approver, time and method; the stamp artwork for stamp and the image only when viewable', async () => {
    mount();
    const a = await screen.findByTestId('approval-seller-1');
    expect(within(a).getByText(/Disetujui oleh Budi Penyetuju/)).toBeInTheDocument();
    expect(within(a).getByTestId('approval-stamp')).toBeInTheDocument();

    const b = screen.getByTestId('approval-seller-2');
    expect(await within(b).findByTestId('approval-image')).toBeInTheDocument();
    expect(getApprovalImageBlobUrl).toHaveBeenCalledWith(7, 32);
  });

  it('does not request an image the server says the user cannot view', async () => {
    getApprovals.mockResolvedValue({ ...PARTIAL, sellers: [{ ...B_DONE_IMG, approval: { ...B_DONE_IMG.approval, can_view_image: false } }] });
    mount();
    await screen.findByTestId('approval-image-restricted');
    expect(getApprovalImageBlobUrl).not.toHaveBeenCalled();
  });

  it('offers Approve only where the server set can_approve (and the role has the action)', async () => {
    mount();
    await screen.findByTestId('approval-overall');
    expect(within(screen.getByTestId('approval-seller-3')).getByTestId('approval-approve')).toBeInTheDocument();
    expect(within(screen.getByTestId('approval-seller-1')).queryByTestId('approval-approve')).toBeNull();
  });

  it('hides Approve for a role without the approve action even when can_approve is true', async () => {
    mount({ sales: ['view'] });
    await screen.findByTestId('approval-overall');
    expect(screen.queryByTestId('approval-approve')).toBeNull();
  });

  it('an owner/admin (not linked to the seller) is told they approve on behalf; the seller themselves is not', async () => {
    mount(undefined, null);
    await fireEvent.click(await within(await screen.findByTestId('approval-seller-3')).findByTestId('approval-approve'));
    expect(await screen.findByTestId('on-behalf-note')).toHaveTextContent('Seller C');
  });

  it('a seller approving their own items sees no on-behalf notice', async () => {
    mount();
    await fireEvent.click(await within(await screen.findByTestId('approval-seller-3')).findByTestId('approval-approve'));
    await screen.findByTestId('approve-seller-modal');
    expect(screen.queryByTestId('on-behalf-note')).toBeNull();
  });

  it('shows the full approval history only when the server sent it (owner/admin), including invalidated cycles', async () => {
    getApprovals.mockResolvedValue({
      ...PARTIAL,
      history: [
        { id: 40, artist_id: 3, artist_name: 'Seller C', method: 'stamp', approver_name: 'Owner', approved_at: '2026-11-05T12:00:00+07:00', on_behalf: true, invalidated_at: null, invalidation_reason: null },
        { id: 31, artist_id: 3, artist_name: 'Seller C', method: 'draw', approver_name: 'C User', approved_at: '2026-11-05T11:00:00+07:00', on_behalf: false, invalidated_at: '2026-11-05T11:30:00+07:00', invalidation_reason: 'items_changed' },
      ],
    });
    mount();
    const box = await screen.findByTestId('approval-history');
    expect(box).toHaveTextContent('Riwayat persetujuan (2)');
    expect(within(screen.getByTestId('approval-history-31')).getByText(/dibatalkan — item berubah/i)).toBeInTheDocument();
    expect(within(screen.getByTestId('approval-history-40')).queryByText(/dibatalkan/i)).toBeNull();
  });

  it('marks history entries created by a bulk approval (064) and only those', async () => {
    getApprovals.mockResolvedValue({
      ...PARTIAL,
      history: [
        { id: 41, artist_id: 3, artist_name: 'Seller C', method: 'stamp', approver_name: 'Owner', approved_at: '2026-11-05T12:00:00+07:00', on_behalf: true, via_bulk: true, invalidated_at: null, invalidation_reason: null },
        { id: 42, artist_id: 1, artist_name: 'Seller A', method: 'stamp', approver_name: 'Owner', approved_at: '2026-11-05T12:05:00+07:00', on_behalf: true, via_bulk: false, invalidated_at: null, invalidation_reason: null },
      ],
    });
    mount();
    await screen.findByTestId('approval-history');
    expect(screen.getByTestId('approval-history-bulk-41')).toHaveTextContent(/lewat persetujuan massal/i);
    expect(screen.queryByTestId('approval-history-bulk-42')).toBeNull();
  });

  it('does not render a history block when the payload has none (non-admin)', async () => {
    mount();
    await screen.findByTestId('approval-overall');
    expect(screen.queryByTestId('approval-history')).toBeNull();
  });

  it('opening the page never approves anything', async () => {
    mount();
    await screen.findByTestId('approval-overall');
    expect(approveSeller).not.toHaveBeenCalled();
  });

  it('approving: confirm → API gets seller, method and the reviewed hash → status refreshes and the parent is told', async () => {
    approveSeller.mockResolvedValue({ ...PARTIAL, status: 'full', approved: 3, pending: 0, progress: '3/3', sellers: [A_DONE, B_DONE_IMG, { ...C_PENDING, status: 'approved', can_approve: false, approval: { ...A_DONE.approval, id: 40, approver_name: 'C User' } }] });
    const { emitted } = mount();
    await fireEvent.click(await within(await screen.findByTestId('approval-seller-3')).findByTestId('approval-approve'));

    await fireEvent.click(await screen.findByTestId('method-stamp'));
    await fireEvent.click(screen.getByTestId('approval-continue'));
    await fireEvent.click(screen.getByTestId('approval-confirm'));

    await waitFor(() => expect(approveSeller).toHaveBeenCalledTimes(1));
    expect(approveSeller).toHaveBeenCalledWith(7, { artistId: 3, method: 'stamp', itemsHash: 'v1:c', image: null });
    await waitFor(() => expect(screen.getByTestId('approval-overall-status')).toHaveTextContent('Disetujui Penuh'));
    expect(toast.success).toHaveBeenCalled();
    expect(emitted().changed).toHaveLength(1);
  });

  it('a stale/already-approved answer closes the dialog and reloads the status', async () => {
    approveSeller.mockRejectedValue(Object.assign(new Error('Item berubah'), { code: 'approval_stale', status: 409 }));
    mount();
    await fireEvent.click(await within(await screen.findByTestId('approval-seller-3')).findByTestId('approval-approve'));
    await fireEvent.click(await screen.findByTestId('method-stamp'));
    await fireEvent.click(screen.getByTestId('approval-continue'));
    await fireEvent.click(screen.getByTestId('approval-confirm'));

    await waitFor(() => expect(getApprovals).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.queryByTestId('approval-confirm-step')).toBeNull());
  });

  it('a failed load is a failure state with Retry, never an empty state', async () => {
    getApprovals.mockRejectedValueOnce(new Error('Gagal'));
    mount();
    expect(await screen.findByTestId('approval-load-error')).toHaveTextContent('Gagal');
    expect(screen.queryByTestId('approval-overall')).toBeNull();

    await fireEvent.click(screen.getByTestId('approval-retry'));
    expect(await screen.findByTestId('approval-overall')).toBeInTheDocument();
  });

  it('shows the re-approval cue for a stale seller', async () => {
    getApprovals.mockResolvedValue({ ...PARTIAL, sellers: [{ ...C_PENDING, stale: true }] });
    mount();
    expect(await screen.findByTestId('approval-stale')).toHaveTextContent(/disetujui ulang/i);
  });
});
