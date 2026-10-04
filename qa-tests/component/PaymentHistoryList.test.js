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

/**
 * 031-optional-payment-proof (FR-005/FR-006/FR-014) — bukti bayar opsional: entri
 * non-tunai tanpa bukti ditandai "Belum ada bukti"; catatan tampil; aksi tambah/ubah
 * konfirmasi dan "Lihat bukti" mengikuti flag yang dihitung SERVER (`can_edit_confirmation`,
 * `can_view_proof`) — SPA tidak menebak dari peran.
 */
describe('PaymentHistoryList — confirmation (031)', () => {
  const entry = (overrides = {}) => ({
    id: 10, method: 'qr_ewallet', amount: '50000.00', paid_at: '2026-10-04T05:00:00Z', provider: 'Shopee',
    reference: null, notes: null, recorded_by_name: 'Kasir Satu', status: 'paid',
    proof_id: null, has_proof: false, can_view_proof: false, can_edit_confirmation: false,
    ...overrides,
  });

  it('marks a non-cash entry without a proof, and does not mark cash or entries that have one', () => {
    render(PaymentHistoryList, {
      props: {
        payments: [
          entry({ id: 1 }),
          entry({ id: 2, method: 'cash', provider: null }),
          entry({ id: 3, proof_id: 7, has_proof: true, can_view_proof: true }),
        ],
      },
    });

    expect(within(rows()[0]).getByText('Belum ada bukti')).toBeInTheDocument();
    expect(within(rows()[1]).queryByText('Belum ada bukti')).not.toBeInTheDocument();
    expect(within(rows()[2]).queryByText('Belum ada bukti')).not.toBeInTheDocument();
  });

  it('does not claim "no proof" for entries from endpoints that never loaded proofs (no has_proof field)', () => {
    render(PaymentHistoryList, { props: { payments: PAYMENTS } });

    expect(screen.queryByText('Belum ada bukti')).not.toBeInTheDocument();
  });

  it('shows the notes of an entry, and nothing when there are none', () => {
    render(PaymentHistoryList, { props: { payments: [entry({ id: 1, notes: 'Transfer dari BCA' }), entry({ id: 2 })] } });

    expect(within(rows()[0]).getByTestId('payment-notes')).toHaveTextContent('Transfer dari BCA');
    expect(within(rows()[1]).queryByTestId('payment-notes')).not.toBeInTheDocument();
  });

  it('offers View proof only when the entry has a proof AND the user may open it', () => {
    render(PaymentHistoryList, {
      props: {
        payments: [
          entry({ id: 1, proof_id: 7, has_proof: true, can_view_proof: true }),
          entry({ id: 2, proof_id: 8, has_proof: true, can_view_proof: false }),
        ],
      },
    });

    expect(within(rows()[0]).getByRole('button', { name: /lihat bukti/i })).toBeInTheDocument();
    expect(within(rows()[1]).queryByRole('button', { name: /lihat bukti/i })).not.toBeInTheDocument();
  });

  it('offers "Tambah konfirmasi" when nothing is recorded yet and "Ubah konfirmasi" once something is, only when allowed', () => {
    render(PaymentHistoryList, {
      props: {
        payments: [
          entry({ id: 1, can_edit_confirmation: true }),
          entry({ id: 2, can_edit_confirmation: true, reference: 'TRX-1' }),
          entry({ id: 3, can_edit_confirmation: true, proof_id: 5, has_proof: true, can_view_proof: true }),
          entry({ id: 4, can_edit_confirmation: false }),
          entry({ id: 5, method: 'cash', provider: null, can_edit_confirmation: false }),
        ],
      },
    });

    expect(within(rows()[0]).getByRole('button', { name: 'Tambah konfirmasi' })).toBeInTheDocument();
    expect(within(rows()[1]).getByRole('button', { name: 'Ubah konfirmasi' })).toBeInTheDocument();
    expect(within(rows()[2]).getByRole('button', { name: 'Ubah konfirmasi' })).toBeInTheDocument();
    expect(within(rows()[3]).queryByRole('button', { name: /konfirmasi/i })).not.toBeInTheDocument();
    expect(within(rows()[4]).queryByRole('button', { name: /konfirmasi/i })).not.toBeInTheDocument();
  });

  it('emits edit-confirmation with the entry when the action is clicked', async () => {
    const user = userEvent.setup();
    const target = entry({ id: 1, can_edit_confirmation: true });
    const { emitted } = render(PaymentHistoryList, { props: { payments: [target] } });

    await user.click(screen.getByRole('button', { name: 'Tambah konfirmasi' }));

    expect(emitted()['edit-confirmation'][0]).toEqual([target]);
  });

  it('renders entries from before this feature (none of the new fields) exactly as before', () => {
    render(PaymentHistoryList, { props: { payments: PAYMENTS, canDelete: false } });

    expect(rows()).toHaveLength(3);
    expect(screen.queryByRole('button', { name: /konfirmasi/i })).not.toBeInTheDocument();
    expect(within(rows()[1]).getByRole('button', { name: /lihat bukti/i })).toBeInTheDocument(); // proof_id sudah ada, tanpa flag => tetap tampil
  });
});

/**
 * 032-mark-payment-verified (US1, FR-001/FR-002/FR-003) — pembayaran non-tunai ditandai
 * "Belum terverifikasi" sampai diverifikasi; aksi "Tandai terverifikasi" hanya muncul bila
 * SERVER menyatakan `can_verify` (pencatat pembayaran tidak boleh memverifikasi miliknya).
 */
describe('PaymentHistoryList — verification (032)', () => {
  const entry = (overrides = {}) => ({
    id: 10, method: 'qr_ewallet', amount: '50000.00', paid_at: '2026-10-04T05:00:00Z', provider: 'Shopee',
    reference: null, notes: null, recorded_by_name: 'Kasir Satu', status: 'paid',
    verification: 'pending', can_verify: false,
    ...overrides,
  });

  it('marks a pending non-cash payment "Belum terverifikasi" and a verified one "Terverifikasi"', () => {
    render(PaymentHistoryList, { props: { payments: [entry({ id: 1 }), entry({ id: 2, verification: 'verified' })] } });

    expect(within(rows()[0]).getByText('Belum terverifikasi')).toBeInTheDocument();
    expect(within(rows()[1]).getByText('Terverifikasi')).toBeInTheDocument();
    expect(within(rows()[1]).queryByText('Belum terverifikasi')).not.toBeInTheDocument();
  });

  it('shows no verification marker for cash, rejected entries, or entries from before this feature', () => {
    render(PaymentHistoryList, {
      props: {
        payments: [
          entry({ id: 1, method: 'cash', provider: null, verification: 'verified' }),
          entry({ id: 2, verification: 'rejected', status: 'rejected' }),
          { id: 3, method: 'bank_transfer', amount: '1000.00', paid_at: '2026-10-04T05:00:00Z', status: 'paid' },
        ],
      },
    });

    for (const row of rows()) {
      expect(within(row).queryByText('Belum terverifikasi')).not.toBeInTheDocument();
      expect(within(row).queryByText('Terverifikasi')).not.toBeInTheDocument();
    }
  });

  it('offers "Tandai terverifikasi" only on a pending entry the server says may be verified, and emits verify with the entry', async () => {
    const user = userEvent.setup();
    const target = entry({ id: 1, can_verify: true });
    const { emitted } = render(PaymentHistoryList, {
      props: { payments: [target, entry({ id: 2, can_verify: false }), entry({ id: 3, verification: 'verified', can_verify: true })] },
    });

    expect(within(rows()[0]).getByRole('button', { name: 'Tandai terverifikasi' })).toBeInTheDocument();
    expect(within(rows()[1]).queryByRole('button', { name: 'Tandai terverifikasi' })).not.toBeInTheDocument();
    expect(within(rows()[2]).queryByRole('button', { name: 'Tandai terverifikasi' })).not.toBeInTheDocument();

    await user.click(within(rows()[0]).getByRole('button', { name: 'Tandai terverifikasi' }));
    expect(emitted().verify[0]).toEqual([target]);
  });

  it('never offers any way to undo a verification', () => {
    render(PaymentHistoryList, { props: { payments: [entry({ verification: 'verified', can_verify: true })] } });

    expect(screen.queryByRole('button', { name: /batal.*verifikasi|belum terverifikasi|unverify|undo/i })).not.toBeInTheDocument();
  });
});

/**
 * 032-mark-payment-verified (US2, FR-004) — pembayaran yang sudah diverifikasi menampilkan
 * siapa dan kapan; entri lama (tunai, atau sebelum fitur ini) tampil seperti biasa.
 */
describe('PaymentHistoryList — who verified and when (032 US2)', () => {
  const entry = (overrides = {}) => ({
    id: 10, method: 'qr_ewallet', amount: '50000.00', paid_at: '2026-10-04T05:00:00Z', provider: 'Shopee',
    reference: null, notes: null, recorded_by_name: 'Kasir Satu', status: 'paid',
    verification: 'verified', can_verify: false, verified_by_name: 'Pemeriksa Satu', verified_at: '2026-10-04T08:30:00Z',
    ...overrides,
  });

  it('shows "Diverifikasi oleh {nama} · {tanggal/waktu}" for a verified non-cash entry', () => {
    render(PaymentHistoryList, { props: { payments: [entry()] } });

    const line = within(rows()[0]).getByTestId('verified-by');
    expect(line).toHaveTextContent('Diverifikasi oleh Pemeriksa Satu');
    expect(line).toHaveTextContent(/2026/);
  });

  it('falls back to "Diverifikasi · {tanggal/waktu}" when the verifier is unknown, never printing null/undefined', () => {
    render(PaymentHistoryList, { props: { payments: [entry({ verified_by_name: null })] } });

    const line = within(rows()[0]).getByTestId('verified-by');
    expect(line).toHaveTextContent(/^Diverifikasi · /);
    expect(line).not.toHaveTextContent(/null|undefined/);
  });

  it('shows just the pill when neither the verifier nor the time is known', () => {
    render(PaymentHistoryList, { props: { payments: [entry({ verified_by_name: null, verified_at: null })] } });

    expect(within(rows()[0]).getByText('Terverifikasi')).toBeInTheDocument();
    expect(within(rows()[0]).queryByTestId('verified-by')).not.toBeInTheDocument();
  });

  it('never shows a verifier line for cash or for a pending entry', () => {
    render(PaymentHistoryList, {
      props: { payments: [entry({ id: 1, method: 'cash', provider: null }), entry({ id: 2, verification: 'pending', verified_by_name: null, verified_at: null })] },
    });

    for (const row of rows()) expect(within(row).queryByTestId('verified-by')).not.toBeInTheDocument();
  });
});

