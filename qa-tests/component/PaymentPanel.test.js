import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PaymentPanel from '../../resources/js/components/payment/PaymentPanel.vue';
import { uploadPaymentProof } from '../../resources/js/api/payments';

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
    await user.click(screen.getByRole('button', { name: /tambah & lanjutkan/i }));

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
    await user.click(screen.getByRole('button', { name: /tambah & lanjutkan/i }));
    await screen.findByText('Pembayaran tercatat');

    await user.click(screen.getByRole('button', { name: /hapus/i }));

    // The "payments so far" section itself stays visible (010-split-payment
    // makes it a persistent affordance, not something gated on entries
    // existing) — but it reverts to its empty-state hint once the only
    // entry is removed.
    expect(screen.getByText('Pembayaran tercatat')).toBeInTheDocument();
    expect(screen.getByText(/Belum ada pembayaran tercatat/i)).toBeInTheDocument();
    expect(screen.getAllByText('Rp 50.000').length).toBeGreaterThan(0);
  });

  // 010-split-payment-preorder-reports (US1/T002/T004) — the split
  // affordance must be discoverable BEFORE any entry is committed, and the
  // submit button's label must announce what clicking it will actually do.
  it('shows the split-payment hint even with zero entries committed', () => {
    renderPanel({ mode: 'checkout', dueAmount: '50000.00' });

    expect(screen.getByText('Pembayaran tercatat')).toBeInTheDocument();
    expect(screen.getByText(/Belum ada pembayaran tercatat/i)).toBeInTheDocument();
  });

  it('labels the submit button "Add & continue" when the current entry is a partial amount', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPanel({ mode: 'checkout', dueAmount: '50000.00', submitLabel: 'Konfirmasi pembayaran' });

    const cashInput = screen.getByLabelText(/uang diterima/i);
    await user.clear(cashInput);
    await user.type(cashInput, '20000');

    expect(screen.getByRole('button', { name: /tambah & lanjutkan/i })).toBeInTheDocument();
  });

  it('keeps the submitLabel prop text on the button once the current entry covers the remaining balance', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPanel({ mode: 'checkout', dueAmount: '50000.00', submitLabel: 'Konfirmasi pembayaran' });

    const cashInput = screen.getByLabelText(/uang diterima/i);
    await user.clear(cashInput);
    await user.type(cashInput, '50000');

    expect(screen.getByRole('button', { name: /konfirmasi pembayaran/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /tambah & lanjutkan/i })).not.toBeInTheDocument();
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

// 010-split-payment-preorder-reports (US2/T006/T010) — mode="record" (used
// by the Preorder payment-recording flow) now reuses the exact same
// always-visible split mechanism as checkout mode instead of always
// emitting a single payment immediately (research.md R2).
// 028-partial-split-payment — mode "record" tidak lagi menahan entri: satu simpan =
// satu pembayaran yang langsung dikirim, berapa pun jumlahnya (≤ sisa tagihan).
describe('PaymentPanel — record mode (028)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('emits a partial payment immediately as a one-element array instead of accumulating it', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderPanel({ mode: 'record', dueAmount: '100000.00', onSubmit, submitLabel: 'Simpan pembayaran' });

    const amountInput = screen.getByLabelText(/jumlah dibayar/i);
    await user.clear(amountInput);
    await user.type(amountInput, '40000');
    await user.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit).toHaveBeenCalledWith([expect.objectContaining({ method: 'cash', amount: '40000.00', reference: null })]);
    expect(screen.queryByText('Pembayaran tercatat')).not.toBeInTheDocument();
  });

  it('never shows the "Add & continue" wording or the accumulated-entries box', () => {
    renderPanel({ mode: 'record', dueAmount: '100000.00', submitLabel: 'Simpan pembayaran' });

    expect(screen.queryByRole('button', { name: /tambah & lanjutkan/i })).not.toBeInTheDocument();
    expect(screen.queryByText('Pembayaran tercatat')).not.toBeInTheDocument();
  });

  it('refuses an amount above the remaining balance for cash too (no change in record mode)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderPanel({ mode: 'record', dueAmount: '100000.00', submitLabel: 'Simpan pembayaran' });

    const amountInput = screen.getByLabelText(/jumlah dibayar/i);
    await user.clear(amountInput);
    await user.type(amountInput, '100001');

    expect(screen.getByRole('button', { name: /simpan pembayaran/i })).toBeDisabled();
  });

  it('includes the typed reference number on the emitted entry', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderPanel({ mode: 'record', dueAmount: '100000.00', onSubmit, submitLabel: 'Simpan pembayaran' });

    await user.type(screen.getByLabelText(/referensi/i), '  TRX-77 ');
    await user.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith([expect.objectContaining({ reference: 'TRX-77' })]));
  });
});

// 028-partial-split-payment (US5) — checkout: boleh menyelesaikan penjualan dengan
// pembayaran KURANG dari total, tetapi hanya bila ada pelanggan (allowPartial).
describe('PaymentPanel — partial finish in checkout mode (028 US5)', () => {
  beforeEach(() => vi.clearAllMocks());

  async function typeAmount(value) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const input = screen.getByLabelText(/uang diterima/i);
    await user.clear(input);
    await user.type(input, String(value));
    return user;
  }

  it('offers no partial finish without a customer and explains that a customer is needed', async () => {
    renderPanel({ mode: 'checkout', dueAmount: '100000.00', allowPartial: false });

    await typeAmount(40000);

    expect(screen.queryByTestId('finish-partial')).not.toBeInTheDocument();
    expect(screen.getByTestId('partial-needs-customer')).toBeInTheDocument();
  });

  it('with a customer, offers to finish with the remaining balance and emits the entries', async () => {
    const onSubmitPartial = vi.fn();
    renderPanel({ mode: 'checkout', dueAmount: '100000.00', allowPartial: true, onSubmitPartial });

    const user = await typeAmount(40000);
    const finish = await screen.findByTestId('finish-partial');
    expect(finish).toHaveTextContent('60.000');
    await user.click(finish);

    expect(onSubmitPartial).toHaveBeenCalledTimes(1);
    expect(onSubmitPartial).toHaveBeenCalledWith([expect.objectContaining({ method: 'cash', amount: '40000.00' })]);
  });

  it('includes already accumulated entries when finishing partially', async () => {
    const onSubmitPartial = vi.fn();
    renderPanel({ mode: 'checkout', dueAmount: '100000.00', allowPartial: true, onSubmitPartial });

    const user = await typeAmount(30000);
    await user.click(screen.getByRole('button', { name: /tambah & lanjutkan/i })); // entri 1 = 30.000
    await typeAmount(20000);
    await user.click(await screen.findByTestId('finish-partial'));

    expect(onSubmitPartial).toHaveBeenCalledWith([
      expect.objectContaining({ amount: '30000.00' }),
      expect.objectContaining({ amount: '20000.00' }),
    ]);
  });

  it('hides the partial finish when the current entry already covers the total', async () => {
    renderPanel({ mode: 'checkout', dueAmount: '100000.00', allowPartial: true });

    await typeAmount(100000);

    expect(screen.queryByTestId('finish-partial')).not.toBeInTheDocument();
    expect(screen.queryByTestId('partial-needs-customer')).not.toBeInTheDocument();
  });

  it('never shows the partial controls in record mode', async () => {
    renderPanel({ mode: 'record', dueAmount: '100000.00', allowPartial: true });

    expect(screen.queryByTestId('finish-partial')).not.toBeInTheDocument();
    expect(screen.queryByTestId('partial-needs-customer')).not.toBeInTheDocument();
  });
});

/**
 * 031-optional-payment-proof — foto/berkas bukti bayar OPSIONAL untuk pembayaran
 * non-tunai (checkout dan "tambah pembayaran"); bisa ditambahkan belakangan dari
 * detail transaksi. Dulu tombol konfirmasi mati sampai bukti diunggah.
 */
describe('PaymentPanel — proof is optional for non-cash (031)', () => {
  // ProofCapture sungguhan butuh kamera/canvas; stub memancarkan event `captured` yang sama.
  const ProofCaptureStub = {
    emits: ['captured', 'cleared'],
    setup(_, { emit }) {
      return { capture: () => emit('captured', new File(['x'], 'bukti.jpg'), 'upload') };
    },
    template: '<button type="button" data-testid="stub-capture" @click="capture">stub bukti</button>',
  };

  function renderWithStub(props) {
    const pinia = createPinia();
    setActivePinia(pinia);
    return render(PaymentPanel, { props, global: { plugins: [pinia], stubs: { ProofCapture: ProofCaptureStub } } });
  }

  beforeEach(() => vi.clearAllMocks());

  it('checkout: confirms a QRIS payment with NO proof and sends proof_token null', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderWithStub({ mode: 'checkout', dueAmount: '30000.00', onSubmit });

    await user.click(screen.getByRole('radio', { name: /qris/i }));
    const confirm = screen.getByRole('button', { name: /konfirmasi pembayaran/i });
    await waitFor(() => expect(confirm).toBeEnabled());
    await user.click(confirm);

    expect(onSubmit).toHaveBeenCalledWith([expect.objectContaining({ method: 'qr_ewallet', channel_id: 1, proof_token: null })]);
    expect(uploadPaymentProof).not.toHaveBeenCalled();
  });

  it('checkout: still sends the proof token when a proof was attached', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderWithStub({ mode: 'checkout', dueAmount: '30000.00', onSubmit });

    await user.click(screen.getByRole('radio', { name: /qris/i }));
    await user.click(screen.getByTestId('stub-capture'));
    await waitFor(() => expect(uploadPaymentProof).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole('button', { name: /konfirmasi pembayaran/i }));

    expect(onSubmit).toHaveBeenCalledWith([expect.objectContaining({ method: 'qr_ewallet', proof_token: 'fake-token' })]);
  });

  it('checkout: the confirm action stays disabled while a proof upload is still in flight', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    let finish;
    uploadPaymentProof.mockReturnValueOnce(new Promise((resolve) => { finish = resolve; }));
    renderWithStub({ mode: 'checkout', dueAmount: '30000.00', onSubmit: vi.fn() });

    await user.click(screen.getByRole('radio', { name: /qris/i }));
    await user.click(screen.getByTestId('stub-capture'));

    expect(screen.getByRole('button', { name: /konfirmasi pembayaran/i })).toBeDisabled();
    finish({ proof_token: 'late-token', file_size: 1 });
    await waitFor(() => expect(screen.getByRole('button', { name: /konfirmasi pembayaran/i })).toBeEnabled());
  });

  it('record mode: saves a non-cash payment without a proof', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderWithStub({ mode: 'record', dueAmount: '100000.00', onSubmit, submitLabel: 'Simpan pembayaran' });

    await user.click(screen.getByRole('radio', { name: /transfer/i }));
    const amountInput = screen.getByLabelText(/jumlah dibayar/i);
    await user.clear(amountInput);
    await user.type(amountInput, '40000');

    // transfer: kanal harus dipilih; mock hanya punya kanal qr_ewallet, jadi pakai QRIS
    await user.click(screen.getByRole('radio', { name: /qris/i }));
    const save = screen.getByRole('button', { name: /simpan pembayaran/i });
    await waitFor(() => expect(save).toBeEnabled());
    await user.click(save);

    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith([expect.objectContaining({ method: 'qr_ewallet', amount: '40000.00', proof_token: null })]));
  });

  it('no longer says a proof must be attached; shows the optional hint instead', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderWithStub({ mode: 'checkout', dueAmount: '30000.00', onSubmit: vi.fn() });

    await user.click(screen.getByRole('radio', { name: /qris/i }));

    expect(screen.queryByText(/wajib dilampirkan/i)).not.toBeInTheDocument();
    expect(screen.getByText(/bukti bersifat opsional/i)).toBeInTheDocument();
  });
});
