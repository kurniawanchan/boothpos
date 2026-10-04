import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import PaymentHistoryList from '../../resources/js/components/payment/PaymentHistoryList.vue';

const PAYMENTS = [
  { id: 1, method: 'cash', amount: '200000.00', paid_at: '2026-10-04T05:00:00Z', reference: null, recorded_by_name: 'Kasir Satu', status: 'paid', proof_id: null },
  { id: 2, method: 'bank_transfer', amount: '200000.00', paid_at: '2026-10-04T06:30:00Z', reference: 'TRX-8841', recorded_by_name: null, status: 'paid', proof_id: 9 },
  { id: 3, method: 'qr_ewallet', amount: '50000.00', paid_at: '2026-10-04T07:00:00Z', reference: null, recorded_by_name: 'Kasir Dua', status: 'rejected', proof_id: null },
];

const rows = () => screen.getAllByTestId('payment-entry');

/**
 * 028-partial-split-payment (US3) — riwayat pembayaran: metode, jumlah, waktu,
 * referensi, pencatat, status, urutan dicatat; tanpa aksi ubah.
 */
describe('PaymentHistoryList (028 US3)', () => {
  it('lists every payment in the order given with method, amount, date/time and status', () => {
    render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: false } });

    expect(rows()).toHaveLength(3);
    expect(rows()[0]).toHaveTextContent('Tunai');
    expect(rows()[0]).toHaveTextContent('200.000');
    expect(rows()[0]).toHaveTextContent(/2026/);
    expect(rows()[0]).toHaveTextContent('Dibayar');
    expect(rows()[1]).toHaveTextContent('Transfer');
    expect(rows()[2]).toHaveTextContent('Ditolak');
  });

  it('shows the reference when there is one and nothing when there is not', () => {
    render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: false } });

    expect(rows()[1]).toHaveTextContent('TRX-8841');
    expect(within(rows()[0]).queryByTestId('payment-reference')).not.toBeInTheDocument();
  });

  it('shows who recorded the payment, and omits it for rows recorded before this feature', () => {
    render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: false } });

    expect(rows()[0]).toHaveTextContent('Kasir Satu');
    expect(within(rows()[1]).queryByTestId('payment-recorder')).not.toBeInTheDocument();
  });

  it('shows the empty-history message when there are no payments', () => {
    render(PaymentHistoryList, { props: { payments: [], canDelete: false } });

    expect(screen.getByText(/belum ada pembayaran tercatat/i)).toBeInTheDocument();
    expect(screen.queryAllByTestId('payment-entry')).toHaveLength(0);
  });

  it('offers View proof only for entries with a proof, and Payment invoice for every entry', () => {
    render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: false } });

    expect(within(rows()[0]).queryByRole('button', { name: /lihat bukti/i })).not.toBeInTheDocument();
    expect(within(rows()[1]).getByRole('button', { name: /lihat bukti/i })).toBeInTheDocument();
    expect(within(rows()[0]).getByRole('button', { name: /invoice pembayaran/i })).toBeInTheDocument();
  });

  it('never offers an edit action, and offers Delete only when canDelete is true', () => {
    const { unmount } = render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: false } });
    expect(screen.queryByRole('button', { name: /ubah|edit/i })).not.toBeInTheDocument();
    expect(screen.queryByTestId('delete-payment')).not.toBeInTheDocument();
    unmount();

    render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: true } });
    expect(screen.queryByRole('button', { name: /ubah|edit/i })).not.toBeInTheDocument();
    expect(screen.getAllByTestId('delete-payment')).toHaveLength(3);
  });

  it('emits view-proof, print and delete with the payment entry', async () => {
    const user = userEvent.setup();
    const { emitted } = render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: true } });

    await user.click(within(rows()[1]).getByRole('button', { name: /lihat bukti/i }));
    await user.click(within(rows()[0]).getByRole('button', { name: /invoice pembayaran/i }));
    await user.click(within(rows()[0]).getByTestId('delete-payment'));

    expect(emitted()['view-proof'][0]).toEqual([PAYMENTS[1]]);
    expect(emitted().print[0]).toEqual([PAYMENTS[0]]);
    expect(emitted().delete[0]).toEqual([PAYMENTS[0]]);
  });
});
