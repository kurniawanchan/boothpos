import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PaymentPanel from '../../resources/js/components/payment/PaymentPanel.vue';

vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'QRIS Toko', type: 'qr_ewallet' }] }),
  uploadPaymentProof: vi.fn().mockResolvedValue({ proof_token: 'fake-token', file_size: 100 }),
}));

function renderPanel(props) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(PaymentPanel, { props, global: { plugins: [pinia] } });
}

// 006-purchase-order-and-ops (US2/US3) — checkout mode now supports split
// payment (multiple entries) and a per-entry note, per research.md R2/R3.
describe('PaymentPanel — split payment & notes (checkout mode)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('submits a single cash entry as a one-element array when it fully covers the total', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderPanel({ mode: 'checkout', dueAmount: '50000.00', onSubmit });

    const cashInput = screen.getByLabelText(/uang diterima/i);
    await user.clear(cashInput);
    await user.type(cashInput, '50000');
    await user.click(screen.getByRole('button', { name: /konfirmasi pembayaran/i }));

    expect(onSubmit).toHaveBeenCalledWith([
      expect.objectContaining({ method: 'cash', amount: '50000.00' }),
    ]);
  });

  it('splits a cash entry plus a second entry, showing the remaining balance in between', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderPanel({ mode: 'checkout', dueAmount: '50000.00', onSubmit });

    const cashInput = screen.getByLabelText(/uang diterima/i);
    await user.clear(cashInput);
    await user.type(cashInput, '20000');
    await user.click(screen.getByRole('button', { name: /konfirmasi pembayaran/i }));

    // First entry (20000 of 50000) doesn't cover the total — it should be
    // committed to the "payments so far" list, not submitted yet, and the
    // remaining balance shown should drop to 30000.
    expect(onSubmit).not.toHaveBeenCalled();
    await screen.findByText('Pembayaran tercatat');
    expect(screen.getAllByText('Rp 30.000').length).toBeGreaterThan(0);

    const secondCashInput = screen.getByLabelText(/uang diterima/i);
    await user.clear(secondCashInput);
    await user.type(secondCashInput, '30000');
    await user.click(screen.getByRole('button', { name: /konfirmasi pembayaran/i }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith([
      expect.objectContaining({ method: 'cash', amount: '20000.00' }),
      expect.objectContaining({ method: 'cash', amount: '30000.00' }),
    ]));
  });

  it('a committed entry can be removed from the split before finishing', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPanel({ mode: 'checkout', dueAmount: '50000.00' });

    const cashInput = screen.getByLabelText(/uang diterima/i);
    await user.clear(cashInput);
    await user.type(cashInput, '20000');
    await user.click(screen.getByRole('button', { name: /konfirmasi pembayaran/i }));
    await screen.findByText('Pembayaran tercatat');

    await user.click(screen.getByRole('button', { name: /hapus/i }));

    expect(screen.queryByText('Pembayaran tercatat')).not.toBeInTheDocument();
    expect(screen.getAllByText('Rp 50.000').length).toBeGreaterThan(0);
  });

  it('includes a note on the submitted entry when one is typed', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderPanel({ mode: 'checkout', dueAmount: '50000.00', onSubmit });

    const cashInput = screen.getByLabelText(/uang diterima/i);
    await user.clear(cashInput);
    await user.type(cashInput, '50000');
    await user.type(screen.getByLabelText(/catatan/i), 'Uang robek, sudah diverifikasi.');
    await user.click(screen.getByRole('button', { name: /konfirmasi pembayaran/i }));

    expect(onSubmit).toHaveBeenCalledWith([
      expect.objectContaining({ notes: 'Uang robek, sudah diverifikasi.' }),
    ]);
  });
});
