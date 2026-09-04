import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import RecordPaymentModal from '../../resources/js/components/payment/RecordPaymentModal.vue';
import { createPreorderPayment } from '../../resources/js/api/preorders';

vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'QRIS Toko', type: 'qr_ewallet' }] }),
  uploadPaymentProof: vi.fn().mockResolvedValue({ proof_token: 'fake-token', file_size: 100 }),
}));

vi.mock('../../resources/js/api/preorders', () => ({
  createPreorderPayment: vi.fn(),
}));

function renderModal(props) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(RecordPaymentModal, {
    props: { open: true, preorderId: 42, dueAmount: '100000.00', purpose: 'down_payment', ...props },
    global: { plugins: [pinia] },
  });
}

// 010-split-payment-preorder-reports (US2/T007/T008/T010) — split entries
// are submitted sequentially to the existing POST /preorders/{id}/payments
// endpoint (research.md R2), each awaited before the next starts, with
// per-entry failure isolation and a per-entry retry.
describe('RecordPaymentModal — sequential split submission', () => {
  beforeEach(() => vi.clearAllMocks());

  it('submits split entries sequentially, in order, one call per entry', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createPreorderPayment.mockResolvedValue({});
    const onSaved = vi.fn();

    renderModal({ onSaved });

    const amountInput = screen.getByLabelText(/jumlah dibayar/i);
    await fireEvent.update(amountInput, '40000');
    await waitFor(() => expect(amountInput).toHaveValue(40000));
    await user.click(screen.getByRole('button', { name: /tambah & lanjutkan/i }));
    await screen.findByText('Pembayaran tercatat');

    const secondAmountInput = screen.getByLabelText(/jumlah dibayar/i);
    await fireEvent.update(secondAmountInput, '60000');
    await waitFor(() => expect(secondAmountInput).toHaveValue(60000));
    await user.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    await waitFor(() => expect(createPreorderPayment).toHaveBeenCalledTimes(2));
    expect(createPreorderPayment).toHaveBeenNthCalledWith(1, 42, expect.objectContaining({ amount: '40000.00' }));
    expect(createPreorderPayment).toHaveBeenNthCalledWith(2, 42, expect.objectContaining({ amount: '60000.00' }));
    await waitFor(() => expect(onSaved).toHaveBeenCalled());
  });

  it('leaves the first successful call intact and only marks the second entry failed', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createPreorderPayment.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('network error'));
    const onSaved = vi.fn();

    renderModal({ onSaved });

    const amountInput = screen.getByLabelText(/jumlah dibayar/i);
    await fireEvent.update(amountInput, '40000');
    await waitFor(() => expect(amountInput).toHaveValue(40000));
    await user.click(screen.getByRole('button', { name: /tambah & lanjutkan/i }));
    await screen.findByText('Pembayaran tercatat');

    const secondAmountInput = screen.getByLabelText(/jumlah dibayar/i);
    await fireEvent.update(secondAmountInput, '60000');
    await waitFor(() => expect(secondAmountInput).toHaveValue(60000));
    await user.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    await waitFor(() => expect(createPreorderPayment).toHaveBeenCalledTimes(2));
    await screen.findByText(/gagal, coba lagi/i);
    expect(onSaved).not.toHaveBeenCalled();

    // First entry shows saved, only one retry button (for the failed entry).
    expect(screen.getByText('Tersimpan')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /kirim ulang/i })).toHaveLength(1);
  });

  it('retrying resubmits only the failed entry, not the already-succeeded one', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createPreorderPayment.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('network error'));
    const onSaved = vi.fn();

    renderModal({ onSaved });

    const amountInput = screen.getByLabelText(/jumlah dibayar/i);
    await fireEvent.update(amountInput, '40000');
    await waitFor(() => expect(amountInput).toHaveValue(40000));
    await user.click(screen.getByRole('button', { name: /tambah & lanjutkan/i }));
    await screen.findByText('Pembayaran tercatat');

    const secondAmountInput = screen.getByLabelText(/jumlah dibayar/i);
    await fireEvent.update(secondAmountInput, '60000');
    await waitFor(() => expect(secondAmountInput).toHaveValue(60000));
    await user.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    await waitFor(() => expect(createPreorderPayment).toHaveBeenCalledTimes(2));
    await screen.findByText(/gagal, coba lagi/i);

    createPreorderPayment.mockResolvedValueOnce({});
    await user.click(screen.getByRole('button', { name: /kirim ulang/i }));

    await waitFor(() => expect(createPreorderPayment).toHaveBeenCalledTimes(3));
    expect(createPreorderPayment).toHaveBeenNthCalledWith(3, 42, expect.objectContaining({ amount: '60000.00' }));
    await waitFor(() => expect(onSaved).toHaveBeenCalled());
  });
});
