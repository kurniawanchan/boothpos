import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PaymentConfirmationModal from '../../resources/js/components/payment/PaymentConfirmationModal.vue';
import { uploadPaymentProof, updatePaymentConfirmation } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/payments', () => ({
  uploadPaymentProof: vi.fn(),
  updatePaymentConfirmation: vi.fn(),
}));

/**
 * 031-optional-payment-proof (US2, FR-006/FR-007/FR-008/FR-010) — jendela "Bukti & catatan
 * pembayaran": tambah / ubah / ganti bukti, referensi, dan catatan sebuah pembayaran
 * non-tunai belakangan. Foto diunggah dulu (token), lalu satu PATCH berisi HANYA yang berubah.
 */
const PAYMENT = {
  id: 10, method: 'qr_ewallet', provider: 'Shopee', amount: '50000.00',
  reference: null, notes: null, proof_id: null, has_proof: false, can_edit_confirmation: true,
};
const UPDATED = { id: 101, payments: [{ ...PAYMENT, reference: 'TRX-1' }] };

// ProofCapture sungguhan butuh kamera/canvas; stub memancarkan event `captured` yang sama.
const ProofCaptureStub = {
  emits: ['captured', 'cleared'],
  setup(_, { emit }) {
    return { capture: () => emit('captured', new File(['x'], 'bukti.jpg'), 'upload') };
  },
  template: '<button type="button" data-testid="stub-capture" @click="capture">stub bukti</button>',
};

function renderModal(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(PaymentConfirmationModal, {
    props: { open: true, payment: PAYMENT, kind: 'orders', targetId: 101, ...props },
    global: { plugins: [pinia], stubs: { ProofCapture: ProofCaptureStub } },
  });
}

const saveButton = () => screen.getByRole('button', { name: 'Simpan' });

describe('PaymentConfirmationModal (031)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    uploadPaymentProof.mockResolvedValue({ proof_token: 'tok-1', file_size: 10 });
    updatePaymentConfirmation.mockResolvedValue(UPDATED);
  });

  it('opens prefilled with the entry\'s reference and notes, and is titled as proof & notes', () => {
    renderModal({ payment: { ...PAYMENT, reference: 'TRX-OLD', notes: 'catatan lama' } });

    expect(screen.getByText('Bukti & catatan pembayaran')).toBeInTheDocument();
    expect(screen.getByLabelText(/nomor referensi/i)).toHaveValue('TRX-OLD');
    expect(screen.getByLabelText(/catatan \(opsional\)/i)).toHaveValue('catatan lama');
  });

  it('cannot be saved until something is entered or changed, and says so', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    expect(saveButton()).toBeDisabled();
    expect(screen.getByText(/isi bukti, referensi, atau catatan/i)).toBeInTheDocument();

    await user.type(screen.getByLabelText(/nomor referensi/i), 'TRX-1');
    expect(saveButton()).toBeEnabled();
  });

  it('whitespace alone does not count as something to save', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    await user.type(screen.getByLabelText(/nomor referensi/i), '   ');

    expect(saveButton()).toBeDisabled();
  });

  it('sends only the changed text fields in one PATCH, without any upload, then reports the updated record', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const view = renderModal({ payment: { ...PAYMENT, notes: 'tetap' } });

    await user.type(screen.getByLabelText(/nomor referensi/i), '  TRX-1 ');
    await user.click(saveButton());

    await waitFor(() => expect(updatePaymentConfirmation).toHaveBeenCalledWith('orders', 101, 10, { reference: 'TRX-1' }));
    expect(uploadPaymentProof).not.toHaveBeenCalled();
    expect(view.emitted().saved[0]).toEqual([UPDATED]);
    expect(view.emitted().close).toBeTruthy();
  });

  it('uploads a captured photo first and then sends its token with the PATCH', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    await user.click(screen.getByTestId('stub-capture'));
    await waitFor(() => expect(uploadPaymentProof).toHaveBeenCalledTimes(1));
    await user.click(saveButton());

    await waitFor(() => expect(updatePaymentConfirmation).toHaveBeenCalledWith('orders', 101, 10, { proof_token: 'tok-1' }));
  });

  it('keeps Save disabled while a photo is still uploading', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    let finish;
    uploadPaymentProof.mockReturnValueOnce(new Promise((resolve) => { finish = resolve; }));
    renderModal();

    await user.click(screen.getByTestId('stub-capture'));
    expect(saveButton()).toBeDisabled();

    finish({ proof_token: 'late', file_size: 1 });
    await waitFor(() => expect(saveButton()).toBeEnabled());
  });

  it('works for a pre-order payment through the same call with kind "preorders"', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal({ kind: 'preorders', targetId: 55 });

    await user.type(screen.getByLabelText(/catatan \(opsional\)/i), 'DP via transfer');
    await user.click(saveButton());

    await waitFor(() => expect(updatePaymentConfirmation).toHaveBeenCalledWith('preorders', 55, 10, { notes: 'DP via transfer' }));
  });

  it('shows the server\'s refusal and stays open when saving fails', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updatePaymentConfirmation.mockRejectedValueOnce(new Error('Hanya owner/admin yang boleh mengubah.'));
    const view = renderModal();

    await user.type(screen.getByLabelText(/nomor referensi/i), 'X');
    await user.click(saveButton());

    expect(await screen.findByText('Hanya owner/admin yang boleh mengubah.')).toBeInTheDocument();
    expect(view.emitted().saved).toBeFalsy();
    expect(view.emitted().close).toBeFalsy();
    expect(saveButton()).toBeEnabled();
  });

  it('refuses to blank out the only thing that is recorded (nothing left to keep)', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal({ payment: { ...PAYMENT, reference: 'SATU-SATUNYA' } });

    await user.clear(screen.getByLabelText(/nomor referensi/i));

    expect(saveButton()).toBeDisabled();
  });

  it('allows clearing one field while another remains, sending null for the cleared one', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal({ payment: { ...PAYMENT, reference: 'A', notes: 'tetap' } });

    await user.clear(screen.getByLabelText(/nomor referensi/i));
    await user.click(saveButton());

    await waitFor(() => expect(updatePaymentConfirmation).toHaveBeenCalledWith('orders', 101, 10, { reference: null }));
  });

  it('explains that a new photo replaces the current proof (old file stays on record) only when a proof exists', () => {
    const { unmount } = renderModal({ payment: { ...PAYMENT, proof_id: 7, has_proof: true } });
    expect(screen.getByText(/menggantikan bukti saat ini/i)).toBeInTheDocument();
    unmount();

    renderModal();
    expect(screen.queryByText(/menggantikan bukti saat ini/i)).not.toBeInTheDocument();
  });
});
