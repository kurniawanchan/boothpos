import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import PaymentProofsModal from '../../resources/js/components/sales/PaymentProofsModal.vue';
import { getOrder } from '../../resources/js/api/orders';
import { getPaymentProofBlobUrl } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ getPaymentProofBlobUrl: vi.fn() }));

/**
 * 056 (US2) — dialog "Payment Proof" dari daftar Sales: memuat penjualan lewat GET /orders/{id} yang sudah
 * ada, menampilkan HANYA bukti non-tunai yang server nyatakan boleh dilihat (`can_view_proof`), masing-masing
 * berlabel metode + jumlah, dan membukanya sebagai galeri ukuran penuh.
 */
const pay = (overrides = {}) => ({
  id: 1, method: 'qr_ewallet', amount: '85000.00', proof_id: 31, has_proof: true, can_view_proof: true, ...overrides,
});
const order = (payments, id = 7) => ({ id, order_number: `ORD-${id}`, payments });

function renderModal(props = {}) {
  return render(PaymentProofsModal, { props: { open: true, orderId: 7, ...props } });
}

describe('PaymentProofsModal (056 US2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    URL.revokeObjectURL = vi.fn();
    getPaymentProofBlobUrl.mockImplementation((id) => Promise.resolve(`blob:proof-${id}`));
  });

  it('loads the sale and lists only viewable non-cash proofs, each labelled with method and amount', async () => {
    getOrder.mockResolvedValue(order([
      pay({ id: 1, proof_id: 31, amount: '85000.00' }),
      pay({ id: 2, method: 'bank_transfer', proof_id: 32, amount: '15000.00' }),
      pay({ id: 3, proof_id: 33, can_view_proof: false }),            // tak boleh dilihat
      pay({ id: 4, proof_id: null, has_proof: false }),               // tanpa bukti
      pay({ id: 5, method: 'cash', proof_id: 34 }),                   // tunai
    ]));
    renderModal();

    const items = await screen.findAllByTestId('payment-proof-item');
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent('85.000');
    expect(items[0]).toHaveTextContent(/QRIS/);
    expect(items[1]).toHaveTextContent('15.000');
    expect(items[1]).toHaveTextContent(/Transfer/);
    expect(getOrder).toHaveBeenCalledWith(7);
    expect(getPaymentProofBlobUrl).toHaveBeenCalledTimes(2);
    expect(getPaymentProofBlobUrl).not.toHaveBeenCalledWith(33);
  });

  it('opens the clicked proof full size in a gallery that can step to the other proof', async () => {
    getOrder.mockResolvedValue(order([pay({ id: 1, proof_id: 31 }), pay({ id: 2, proof_id: 32 })]));
    renderModal();
    const items = await screen.findAllByTestId('payment-proof-item');
    await waitFor(() => items.forEach((i) => expect(within(i).getByTestId('payment-proof-thumbnail')).not.toBeDisabled()));

    await userEvent.setup().click(within(items[1]).getByTestId('payment-proof-thumbnail'));

    const full = await screen.findByAltText(/QRIS/);
    expect(full).toHaveAttribute('src', 'blob:proof-32');
    expect(screen.getByText('2 / 2')).toBeInTheDocument();
  });

  it('says so when the sale has no proof the viewer can open', async () => {
    getOrder.mockResolvedValue(order([pay({ can_view_proof: false })]));
    renderModal();

    expect(await screen.findByText(/tidak punya bukti pembayaran/i)).toBeInTheDocument();
    expect(screen.queryByTestId('payment-proof-item')).not.toBeInTheDocument();
  });

  it('shows the failure and a Retry instead of a blank dialog, and Retry reloads', async () => {
    getOrder.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce(order([pay()]));
    renderModal();

    expect(await screen.findByText(/tidak bisa dimuat/i)).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: /coba lagi/i }));

    expect(await screen.findByTestId('payment-proof-item')).toBeInTheDocument();
    expect(getOrder).toHaveBeenCalledTimes(2);
  });

  it('drops a slow answer for a previous sale after the dialog is pointed at another one', async () => {
    let resolveFirst;
    getOrder
      .mockImplementationOnce(() => new Promise((r) => { resolveFirst = r; }))
      .mockResolvedValueOnce(order([pay({ id: 9, proof_id: 99, amount: '12345.00' })], 8));
    const { rerender } = renderModal({ orderId: 7 });

    await rerender({ orderId: 8 });
    expect(await screen.findByText(/12\.345/)).toBeInTheDocument();
    resolveFirst(order([pay({ id: 1, proof_id: 31, amount: '85000.00' })]));
    await Promise.resolve();

    expect(screen.queryByText(/85\.000/)).not.toBeInTheDocument();
    expect(getPaymentProofBlobUrl).not.toHaveBeenCalledWith(31);
  });

  it('does not load anything while closed', () => {
    renderModal({ open: false });

    expect(getOrder).not.toHaveBeenCalled();
  });
});
