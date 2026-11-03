import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import PaymentHistoryList from '../../resources/js/components/payment/PaymentHistoryList.vue';
import ChangePaymentMethodModal from '../../resources/js/components/sales/ChangePaymentMethodModal.vue';
import { changePaymentMethod } from '../../resources/js/api/orders';
import { listPaymentChannels } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/orders', () => ({ changePaymentMethod: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ listPaymentChannels: vi.fn() }));

const CHANNELS = [
  { id: 3, type: 'qr_ewallet', provider: 'QRIS BCA', is_active: true },
  { id: 4, type: 'bank_transfer', provider: 'BCA', is_active: true },
  { id: 5, type: 'bank_transfer', provider: 'Mandiri', is_active: true },
];
const cashPayment = (over = {}) => ({ id: 11, method: 'cash', channel_id: null, amount: '50000.00', verification: 'verified', status: 'paid', paid_at: '2026-11-01T10:00:00Z', can_change_method: true, ...over });

// 054 (US7) — koreksi metode pembayaran dari detail penjualan.
describe('PaymentHistoryList — change method action (054)', () => {
  it('shows "Ubah metode" only for entries the server says can change', () => {
    render(PaymentHistoryList, { props: { payments: [cashPayment(), cashPayment({ id: 12, can_change_method: false })] } });
    expect(screen.getAllByTestId('change-method')).toHaveLength(1);
  });

  it('emits change-method with the entry', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = render(PaymentHistoryList, { props: { payments: [cashPayment()] } });

    await user.click(screen.getByTestId('change-method'));
    expect(emitted()['change-method'][0][0]).toMatchObject({ id: 11 });
  });

  it('shows no action at all when the payload carries no flag (older endpoints)', () => {
    const { can_change_method, ...legacy } = cashPayment();
    render(PaymentHistoryList, { props: { payments: [legacy] } });
    expect(screen.queryByTestId('change-method')).not.toBeInTheDocument();
  });
});

describe('ChangePaymentMethodModal (054)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listPaymentChannels.mockResolvedValue({ data: CHANNELS });
    changePaymentMethod.mockResolvedValue({ id: 7, payments: [] });
  });

  async function open(payment = cashPayment()) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const utils = render(ChangePaymentMethodModal, { props: { open: true, orderId: 7, payment } });
    await waitFor(() => expect(listPaymentChannels).toHaveBeenCalled());
    return { user, ...utils };
  }

  it('cannot save until something changes', async () => {
    await open();
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeDisabled();
  });

  it('asks for a channel when switching to a transfer and saves method + channel', async () => {
    const { user, emitted } = await open();

    await user.click(screen.getByRole('radio', { name: /QRIS/i }));
    // satu-satunya kanal QRIS terpilih otomatis (ChannelPicker), baru setelah itu Simpan aktif
    await waitFor(() => expect(screen.getByRole('button', { name: 'Simpan' })).toBeEnabled());

    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(changePaymentMethod).toHaveBeenCalledWith(7, 11, { method: 'qr_ewallet', channel_id: 3 });
    await waitFor(() => expect(emitted().saved).toHaveLength(1));
  });

  it('sends no channel when the target is cash', async () => {
    const { user } = await open(cashPayment({ method: 'qr_ewallet', channel_id: 3 }));

    await user.click(screen.getByRole('radio', { name: /tunai/i }));
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(changePaymentMethod).toHaveBeenCalledWith(7, 11, { method: 'cash' });
  });

  it('warns that a non-cash payment needs verification again', async () => {
    const { user } = await open();
    await user.click(screen.getByRole('radio', { name: /QRIS/i }));
    expect(screen.getByText(/perlu diverifikasi ulang/i)).toBeInTheDocument();
  });

  it('keeps the dialog open and does not report success when the server refuses', async () => {
    changePaymentMethod.mockRejectedValue(Object.assign(new Error('Tunai ini diterima di shift yang sudah ditutup'), { status: 409 }));
    const { user, emitted } = await open();

    await user.click(screen.getByRole('radio', { name: /QRIS/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Simpan' })).toBeEnabled());
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(changePaymentMethod).toHaveBeenCalled());
    expect(emitted().saved).toBeUndefined();
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeEnabled();
  });
});
