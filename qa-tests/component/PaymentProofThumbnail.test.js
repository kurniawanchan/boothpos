import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import PaymentProofThumbnail from '../../resources/js/components/payment/PaymentProofThumbnail.vue';
import { getPaymentProofBlobUrl } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/payments', () => ({ getPaymentProofBlobUrl: vi.fn() }));

/**
 * 056 (US1/US2) — thumbnail bukti pembayaran: dimuat lewat blob (endpoint berotorisasi), placeholder
 * saat gagal, dibebaskan saat bukti berganti/komponen dilepas, jawaban usang dibuang.
 */
describe('PaymentProofThumbnail (056)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    URL.revokeObjectURL = vi.fn();
  });

  it('loads the proof as a blob and shows it; clicking emits open', async () => {
    getPaymentProofBlobUrl.mockResolvedValue('blob:one');
    const { emitted } = render(PaymentProofThumbnail, { props: { proofId: 9, alt: 'Bukti' } });

    const button = screen.getByTestId('payment-proof-thumbnail');
    await waitFor(() => expect(button.querySelector('img')).toHaveAttribute('src', 'blob:one'));
    expect(button).toHaveAccessibleName('Bukti');
    expect(getPaymentProofBlobUrl).toHaveBeenCalledWith(9);

    await userEvent.setup().click(screen.getByTestId('payment-proof-thumbnail'));
    expect(emitted().open).toHaveLength(1);
  });

  it('shows a neutral placeholder (no broken image, not clickable) when the file cannot be loaded', async () => {
    getPaymentProofBlobUrl.mockRejectedValue(new Error('boom'));
    render(PaymentProofThumbnail, { props: { proofId: 9 } });

    expect(await screen.findByTestId('payment-proof-unavailable')).toBeInTheDocument();
    expect(screen.getByTestId('payment-proof-thumbnail').querySelector('img')).toBeNull();
    expect(screen.getByTestId('payment-proof-thumbnail')).toBeDisabled();
  });

  it('refetches and revokes the previous URL when the proof is replaced', async () => {
    getPaymentProofBlobUrl.mockResolvedValueOnce('blob:old').mockResolvedValueOnce('blob:new');
    const { rerender } = render(PaymentProofThumbnail, { props: { proofId: 1 } });
    const img = () => screen.getByTestId('payment-proof-thumbnail').querySelector('img');
    await waitFor(() => expect(img()).toHaveAttribute('src', 'blob:old'));

    await rerender({ proofId: 2 });

    await waitFor(() => expect(img()).toHaveAttribute('src', 'blob:new'));
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:old');
  });

  it('drops a stale answer that arrives after the proof changed', async () => {
    let resolveOld;
    getPaymentProofBlobUrl
      .mockImplementationOnce(() => new Promise((r) => { resolveOld = r; }))
      .mockResolvedValueOnce('blob:new');
    const { rerender } = render(PaymentProofThumbnail, { props: { proofId: 1 } });
    await rerender({ proofId: 2 });
    const img = () => screen.getByTestId('payment-proof-thumbnail').querySelector('img');
    await waitFor(() => expect(img()).toHaveAttribute('src', 'blob:new'));

    resolveOld('blob:stale');
    await Promise.resolve();

    expect(img()).toHaveAttribute('src', 'blob:new');
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:stale');
  });

  it('revokes its URL when unmounted', async () => {
    getPaymentProofBlobUrl.mockResolvedValue('blob:one');
    const { unmount } = render(PaymentProofThumbnail, { props: { proofId: 9 } });
    await waitFor(() => expect(screen.getByTestId('payment-proof-thumbnail').querySelector('img')).not.toBeNull());

    unmount();

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:one');
  });
});
