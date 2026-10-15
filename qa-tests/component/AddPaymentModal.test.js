import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import userEvent from '@testing-library/user-event';
import AddPaymentModal from '../../resources/js/components/payment/AddPaymentModal.vue';

vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'QRIS Toko', type: 'qr_ewallet' }] }),
  uploadPaymentProof: vi.fn().mockResolvedValue({ proof_token: 'fake-token', file_size: 100 }),
}));

function renderModal(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const submitFn = props.submitFn ?? vi.fn().mockResolvedValue({});
  const utils = render(AddPaymentModal, {
    props: { open: true, remaining: '600000.00', title: 'Tambah pembayaran', purpose: 'down_payment', ...props, submitFn },
    global: { plugins: [pinia] },
  });
  return { ...utils, submitFn };
}

const amountInput = () => screen.getByLabelText(/jumlah dibayar/i);
const saveButton = () => screen.getByRole('button', { name: /simpan pembayaran/i });

/**
 * 028-partial-split-payment (US1) — dialog Tambah pembayaran: satu klik
 * menyimpan langsung, jumlah bawaan = sisa tagihan.
 */
describe('AddPaymentModal (028 US1)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('opens with the amount defaulted to the remaining balance and shows that balance', async () => {
    renderModal();

    await waitFor(() => expect(amountInput()).toHaveValue(600000));
    expect(screen.getByText(/600\.000/)).toBeInTheDocument();
  });

  it('saves immediately on a single click with the payment payload and emits saved', async () => {
    const user = userEvent.setup();
    const result = { payment_summary: { status: 'partially_paid' } };
    const { submitFn, emitted } = renderModal({ submitFn: vi.fn().mockResolvedValue(result) });

    await fireEvent.update(amountInput(), '200000');
    await waitFor(() => expect(amountInput()).toHaveValue(200000));
    await user.type(screen.getByLabelText(/referensi/i), 'TRX-8841');
    await user.click(saveButton());

    await waitFor(() => expect(submitFn).toHaveBeenCalledTimes(1));
    expect(submitFn).toHaveBeenCalledWith(expect.objectContaining({
      method: 'cash', amount: '200000.00', reference: 'TRX-8841', purpose: 'down_payment',
    }));
    await waitFor(() => expect(emitted().saved[0]).toEqual([result]));
  });

  it('does not allow saving an amount of zero or above the remaining balance', async () => {
    renderModal();
    await waitFor(() => expect(amountInput()).toHaveValue(600000));

    await fireEvent.update(amountInput(), '700000');
    await waitFor(() => expect(saveButton()).toBeDisabled());

    await fireEvent.update(amountInput(), '0');
    await waitFor(() => expect(saveButton()).toBeDisabled());

    await fireEvent.update(amountInput(), '600000');
    await waitFor(() => expect(saveButton()).toBeEnabled());
  });

  // --- 028 US4: pengaman dan umpan balik ---------------------------------------

  it('calls the submit function once even when Save is double-clicked', async () => {
    const user = userEvent.setup();
    let resolve;
    const submitFn = vi.fn(() => new Promise((r) => { resolve = r; }));
    renderModal({ submitFn });
    await waitFor(() => expect(amountInput()).toHaveValue(600000));

    await user.dblClick(saveButton());

    expect(submitFn).toHaveBeenCalledTimes(1);
    resolve({});
  });

  it('sends a UUID client_ref, reuses it on retry after a failure and uses a new one after a success', async () => {
    const user = userEvent.setup();
    const submitFn = vi.fn()
      .mockRejectedValueOnce(Object.assign(new Error('Network Error'), { isNetwork: true }))
      .mockResolvedValueOnce({ payment_summary: { status: 'partially_paid', remaining: '400000.00' } })
      .mockResolvedValueOnce({ payment_summary: { status: 'fully_paid', remaining: '0.00' } });
    const { rerender } = renderModal({ submitFn });
    await waitFor(() => expect(amountInput()).toHaveValue(600000));

    await user.click(saveButton());
    await waitFor(() => expect(submitFn).toHaveBeenCalledTimes(1));
    const firstRef = submitFn.mock.calls[0][0].client_ref;
    expect(firstRef).toMatch(/^[0-9a-f-]{36}$/);

    // gagal → data tetap, coba lagi dengan kunci yang SAMA
    await waitFor(() => expect(saveButton()).toBeEnabled());
    expect(amountInput()).toHaveValue(600000);
    await user.click(saveButton());
    await waitFor(() => expect(submitFn).toHaveBeenCalledTimes(2));
    expect(submitFn.mock.calls[1][0].client_ref).toBe(firstRef);

    // sukses → dialog ditutup dan dibuka lagi: kunci BARU
    await rerender({ open: false });
    await rerender({ open: true });
    await waitFor(() => expect(amountInput()).toHaveValue(600000));
    await user.click(saveButton());
    await waitFor(() => expect(submitFn).toHaveBeenCalledTimes(3));
    expect(submitFn.mock.calls[2][0].client_ref).not.toBe(firstRef);
  });

  it('disables Save while the request is in flight', async () => {
    const user = userEvent.setup();
    let resolve;
    const submitFn = vi.fn(() => new Promise((r) => { resolve = r; }));
    renderModal({ submitFn });
    await waitFor(() => expect(amountInput()).toHaveValue(600000));

    await user.click(saveButton());

    await waitFor(() => expect(saveButton()).toBeDisabled());
    resolve({});
  });

  it('keeps the entered data and shows the unknown-outcome message on a network error', async () => {
    const user = userEvent.setup();
    const { useToastStore } = await import('../../resources/js/stores/toast');
    const submitFn = vi.fn().mockRejectedValue(Object.assign(new Error('Network Error'), { isNetwork: true }));
    renderModal({ submitFn });
    await waitFor(() => expect(amountInput()).toHaveValue(600000));
    await fireEvent.update(amountInput(), '250000');
    await waitFor(() => expect(amountInput()).toHaveValue(250000));

    await user.click(saveButton());

    await waitFor(() => expect(useToastStore().items.some((i) => /periksa riwayat/i.test(i.message))).toBe(true));
    expect(amountInput()).toHaveValue(250000);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('confirms a saved payment with the amount and the new remaining balance', async () => {
    const user = userEvent.setup();
    const { useToastStore } = await import('../../resources/js/stores/toast');
    renderModal({
      submitFn: vi.fn().mockResolvedValue({ payment_summary: { status: 'partially_paid', remaining: '400000.00' } }),
    });
    await waitFor(() => expect(amountInput()).toHaveValue(600000));
    await fireEvent.update(amountInput(), '200000');
    await waitFor(() => expect(amountInput()).toHaveValue(200000));

    await user.click(saveButton());

    await waitFor(() => expect(useToastStore().items.some((i) => /200\.000/.test(i.message) && /400\.000/.test(i.message))).toBe(true));
  });

  it('says the transaction is now fully paid when nothing remains', async () => {
    const user = userEvent.setup();
    const { useToastStore } = await import('../../resources/js/stores/toast');
    renderModal({
      submitFn: vi.fn().mockResolvedValue({ payment_summary: { status: 'fully_paid', remaining: '0.00' } }),
    });
    await waitFor(() => expect(amountInput()).toHaveValue(600000));

    await user.click(saveButton());

    await waitFor(() => expect(useToastStore().items.some((i) => /lunas/i.test(i.message))).toBe(true));
  });
});
