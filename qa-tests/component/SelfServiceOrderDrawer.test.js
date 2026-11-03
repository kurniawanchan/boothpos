import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import SelfServiceOrderDrawer from '../../resources/js/components/selfservice/SelfServiceOrderDrawer.vue';
import {
  dispatchSelfServiceOrder, fetchProofBlob, getSelfServiceOrder, rejectSelfServiceOrder, verifySelfServiceOrder,
} from '../../resources/js/api/selfServiceOrders';
import { useAuthStore } from '../../resources/js/stores/auth';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/selfServiceOrders', () => ({
  listSelfServiceOrders: vi.fn(), getSelfServiceOrder: vi.fn(), verifySelfServiceOrder: vi.fn(), rejectSelfServiceOrder: vi.fn(),
  dispatchSelfServiceOrder: vi.fn(), fetchProofBlob: vi.fn(),
}));

const detail = (over = {}) => ({
  id: 1, reference_no: 'SS-251103-001', status: 'awaiting_verification', created_at: '2026-11-03T05:00:00+00:00', table: { id: 5, name: '5' },
  service_type: 'dine_in', item_count: 3, subtotal: '55000.00', tax_amount: '0.00', total_amount: '55000.00',
  payment: { method: 'qris', channel: 'QRIS', amount: '55000.00', status: 'waiting_verification', reference: null, verified_by: null, proof_id: 9 },
  rejection: null, order: null,
  lines: [
    { name: 'Kopi Susu', qty: 2, unit_price: '20000.00', line_total: '40000.00', note: 'tanpa gula', modifiers: [{ group: 'Level', option: 'Extra Shot', qty: 1 }] },
    { name: 'Nasi Goreng', qty: 1, unit_price: '15000.00', line_total: '15000.00', note: null, modifiers: [] },
  ],
  ...over,
});

function mountDrawer(permissions = ['payment.qris.verify']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['self_service'], permissions };
  return render(SelfServiceOrderDrawer, { props: { orderId: 1 }, global: { plugins: [pinia] } });
}

describe('SelfServiceOrderDrawer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    URL.createObjectURL = vi.fn(() => 'blob:proof');
    URL.revokeObjectURL = vi.fn();
    getSelfServiceOrder.mockResolvedValue(detail());
    fetchProofBlob.mockResolvedValue(new Blob(['x'], { type: 'image/jpeg' }));
  });

  it('shows the items, the payment details and the proof photo', async () => {
    mountDrawer();

    expect(await screen.findByText('2× Kopi Susu')).toBeInTheDocument();
    expect(screen.getByText('Extra Shot')).toBeInTheDocument();
    expect(screen.getByText('“tanpa gula”')).toBeInTheDocument();
    expect(screen.getByTestId('drawer-total')).toHaveTextContent('Rp 55.000');
    expect(screen.getByTestId('drawer-payment-status')).toHaveTextContent('Menunggu verifikasi');
    expect(await screen.findByTestId('drawer-proof')).toHaveAttribute('src', 'blob:proof');
    expect(fetchProofBlob).toHaveBeenCalledWith(9);
  });

  it('cannot verify until a reference of at least four characters is typed', async () => {
    const user = userEvent.setup();
    mountDrawer();
    const verify = await screen.findByTestId('btn-verify');
    expect(verify).toBeDisabled();

    await user.type(screen.getByLabelText(/referensi pembayaran/i), 'abc');
    expect(verify).toBeDisabled();
    await user.type(screen.getByLabelText(/referensi pembayaran/i), 'd');
    expect(verify).toBeEnabled();
  });

  it('verifies with the reference and then offers "Kirim ke dapur"', async () => {
    verifySelfServiceOrder.mockResolvedValue(detail({ status: 'verified', payment: { ...detail().payment, status: 'verified', reference: 'BCA-1234', verified_by: 'Owner' } }));
    const user = userEvent.setup();
    const { emitted } = mountDrawer();
    await screen.findByTestId('btn-verify');

    await user.type(screen.getByLabelText(/referensi pembayaran/i), 'BCA-1234');
    await user.click(screen.getByTestId('btn-verify'));

    await waitFor(() => expect(verifySelfServiceOrder).toHaveBeenCalledWith(1, 'BCA-1234'));
    expect(await screen.findByTestId('btn-dispatch')).toBeInTheDocument();
    expect(screen.getByTestId('drawer-payment-status')).toHaveTextContent('Terverifikasi');
    expect(emitted().changed).toHaveLength(1);
  });

  it('keeps "Kirim ke dapur" out of reach before the payment is verified', async () => {
    mountDrawer();
    await screen.findByTestId('btn-verify');

    expect(screen.queryByTestId('btn-dispatch')).not.toBeInTheDocument();
  });

  it('sends a verified order to the kitchen and shows the order number', async () => {
    getSelfServiceOrder.mockResolvedValue(detail({ status: 'verified', payment: { ...detail().payment, status: 'verified', reference: 'BCA-1234' } }));
    dispatchSelfServiceOrder.mockResolvedValue({ data: detail({ status: 'dispatched', order: { id: 7, order_number: 'TRX-7' } }), order: { id: 7, order_number: 'TRX-7' }, tickets: 1 });
    const user = userEvent.setup();
    const { emitted } = mountDrawer();

    await user.click(await screen.findByTestId('btn-dispatch'));

    expect(await screen.findByTestId('drawer-order-number')).toHaveTextContent('TRX-7');
    expect(dispatchSelfServiceOrder).toHaveBeenCalledWith(1);
    expect(emitted().changed).toHaveLength(1);
    expect(screen.queryByTestId('btn-dispatch')).not.toBeInTheDocument();
  });

  it.each([
    ['no_open_shift', 'Buka shift di outlet ini dulu.', []],
    ['total_changed', 'Harga atau pajak berubah.', []],
    ['item_unavailable', 'Beberapa item tidak bisa dipesan.', [{ variant_id: 3, name: 'Es Teh', reason: 'sold_out' }]],
  ])('explains a %s failure inside the drawer and keeps the order verified', async (code, message, items) => {
    getSelfServiceOrder.mockResolvedValue(detail({ status: 'verified' }));
    dispatchSelfServiceOrder.mockRejectedValue(new ApiError(message, { status: 409, code, data: { items } }));
    const user = userEvent.setup();
    mountDrawer();

    await user.click(await screen.findByTestId('btn-dispatch'));

    const box = await screen.findByTestId('drawer-problem');
    expect(box).toHaveTextContent(message);
    if (items.length) expect(box).toHaveTextContent('Es Teh');
    expect(screen.getByTestId('btn-dispatch')).toBeEnabled();
  });

  it('rejects only with a reason and then shows it', async () => {
    rejectSelfServiceOrder.mockResolvedValue(detail({ status: 'rejected', rejection: { reason: 'Bukti palsu', by: 'Owner', at: '2026-11-03T05:10:00+00:00' }, payment: { ...detail().payment, status: 'rejected' } }));
    const user = userEvent.setup();
    mountDrawer();
    await screen.findByTestId('btn-verify');

    await user.click(screen.getByRole('button', { name: 'Tolak' }));
    const confirm = await screen.findByRole('dialog', { name: 'Tolak pesanan' });
    expect(confirm).toBeInTheDocument();
    await user.type(screen.getByPlaceholderText(/salah pesan/i), 'Bukti palsu');
    await user.click(screen.getAllByRole('button', { name: 'Tolak' }).at(-1));

    await waitFor(() => expect(rejectSelfServiceOrder).toHaveBeenCalledWith(1, 'Bukti palsu'));
    expect(await screen.findByTestId('drawer-rejection')).toHaveTextContent('Bukti palsu');
  });

  it('hides verify and reject from users without the QRIS permission and explains why', async () => {
    mountDrawer([]);

    expect(await screen.findByTestId('no-permission')).toBeInTheDocument();
    expect(screen.queryByTestId('btn-verify')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Tolak' })).not.toBeInTheDocument();
  });

  it('still lets a user without the permission send an already verified order', async () => {
    getSelfServiceOrder.mockResolvedValue(detail({ status: 'verified' }));
    mountDrawer([]);

    expect(await screen.findByTestId('btn-dispatch')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Tolak' })).not.toBeInTheDocument();
  });

  it('says so when the proof photo cannot be loaded, without hiding the order', async () => {
    fetchProofBlob.mockRejectedValue(new ApiError('403', { status: 403 }));
    mountDrawer();

    expect(await screen.findByText('Foto bukti tidak dapat dimuat.')).toBeInTheDocument();
    expect(screen.getByText('2× Kopi Susu')).toBeInTheDocument();
  });
});
