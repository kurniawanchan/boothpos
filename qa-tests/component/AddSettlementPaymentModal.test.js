import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import AddSettlementPaymentModal from '../../resources/js/components/settlement/AddSettlementPaymentModal.vue';

const SETTLEMENT = { id: 12, balance: '20000.00', direction: 'store_pays_seller' };

function mount(open = true, extra = {}) {
  return render(AddSettlementPaymentModal, { props: { open, settlement: SETTLEMENT, ...extra } });
}

describe('AddSettlementPaymentModal', () => {
  it('prefills the amount with the balance, shows the maximum, and today as the date', () => {
    mount();

    expect(screen.getByLabelText(/^jumlah/i)).toHaveValue(20000);
    expect(screen.getByText(/maksimum Rp 20\.000/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/tanggal bayar/i).value).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(screen.getByText(/dibayarkan ke penjual/i)).toBeInTheDocument();
  });

  it('emits the typed values with a UUID client_ref', async () => {
    const { emitted } = mount();
    await fireEvent.update(screen.getByLabelText(/^jumlah/i), '5000.50');
    await fireEvent.update(screen.getByLabelText(/referensi/i), 'TRF-77');

    await fireEvent.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    const [{ payload, proof }] = emitted().save[0];
    expect(payload.amount).toBe('5000.50');
    expect(payload.reference).toBe('TRF-77');
    expect(payload.method).toBe('bank_transfer');
    expect(payload.client_ref).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(proof).toBeNull();
  });

  it('reuses the SAME client_ref when the user saves again within one opening (safe retry)', async () => {
    const { emitted } = mount();

    await fireEvent.click(screen.getByRole('button', { name: /simpan pembayaran/i }));
    await fireEvent.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    const refs = emitted().save.map(([e]) => e.payload.client_ref);
    expect(refs).toHaveLength(2);
    expect(refs[0]).toBe(refs[1]);
  });

  it('generates a NEW client_ref each time the dialog is reopened', async () => {
    const { emitted, rerender } = mount(true);
    await fireEvent.click(screen.getByRole('button', { name: /simpan pembayaran/i }));
    const first = emitted().save[0][0].payload.client_ref;

    await rerender({ open: false });
    await rerender({ open: true });
    await fireEvent.click(screen.getByRole('button', { name: /simpan pembayaran/i }));

    expect(emitted().save[1][0].payload.client_ref).not.toBe(first);
  });

  it('uses the "received from seller" wording when the seller owes the store', () => {
    mount(true, { settlement: { id: 12, balance: '2000.00', direction: 'seller_pays_store' } });

    expect(screen.getByText(/diterima dari penjual/i)).toBeInTheDocument();
  });

  it('shows server field errors next to the fields', () => {
    mount(true, { errors: { amount: 'Jumlah melebihi sisa; maksimum Rp 20.000.', client_ref: 'dipakai' } });

    expect(screen.getByText(/jumlah melebihi sisa/i)).toBeInTheDocument();
    expect(screen.getByText('dipakai')).toBeInTheDocument();
  });
});
