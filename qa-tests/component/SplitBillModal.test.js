import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import SplitBillModal from '../../resources/js/components/restaurant/SplitBillModal.vue';

vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
}));

const line = (over) => ({
  id: 1, name: 'Nasi Goreng', qty: 3, unit_price: '33333.34', line_total: '100000.02', modifiers: [], note: null,
  sent: false, prep_status: null, ticket_item_id: null, station: null, ...over,
});
const tab = (over) => ({ id: 7, paid_amount: '0.00', total_amount: '100000.02', balance: '100000.02', lines: [line()], ...over });

function renderSplit(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(SplitBillModal, { props: { open: true, tab: tab(), ...props }, global: { plugins: [pinia] } });
}
const inc = (name) => screen.getByRole('button', { name: new RegExp(`tambah ${name}`, 'i') });
const dec = (name) => screen.getByRole('button', { name: new RegExp(`kurangi ${name}`, 'i') });

describe('SplitBillModal', () => {
  beforeEach(() => vi.clearAllMocks());

  it('starts with nothing selected: zero total, a hint, and no payment form', () => {
    renderSplit();

    expect(screen.getByTestId('split-total')).toHaveTextContent('0');
    expect(screen.getByText(/pilih minimal satu item/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /bayar bagian ini/i })).toBeNull();
    expect(screen.getByTestId('split-line-1')).toHaveTextContent('3 di tab');
  });

  it('adds units one by one, totalling in cents without rounding drift', async () => {
    const user = userEvent.setup();
    renderSplit();

    await user.click(inc('nasi goreng'));
    expect(screen.getByTestId('split-qty-1')).toHaveTextContent('1');
    expect(screen.getByTestId('split-total')).toHaveTextContent('33.333');
    expect(screen.getByText('Dipilih: 1 item')).toBeInTheDocument();

    await user.click(inc('nasi goreng'));
    await user.click(inc('nasi goreng'));
    // 3 × 33.333,34 = 100.000,02 — tepat sampai sen, bukan 100.000,02000000001
    expect(screen.getByTestId('split-total')).toHaveTextContent('100.000');
    expect(screen.getByRole('button', { name: /tambah nasi goreng/i })).toBeDisabled(); // sudah di batas
  });

  it('can reduce again, down to zero, which hides the payment form', async () => {
    const user = userEvent.setup();
    renderSplit();
    await user.click(inc('nasi goreng'));
    expect(await screen.findByRole('button', { name: /bayar bagian ini/i })).toBeInTheDocument();

    await user.click(dec('nasi goreng'));

    expect(screen.getByTestId('split-qty-1')).toHaveTextContent('0');
    expect(screen.queryByRole('button', { name: /bayar bagian ini/i })).toBeNull();
    expect(dec('nasi goreng')).toBeDisabled();
  });

  it('only allows a sent-but-not-served line to be split whole, in one click', async () => {
    const user = userEvent.setup();
    renderSplit({ tab: tab({ lines: [line({ sent: true, prep_status: 'preparing', ticket_item_id: 9 })] }) });

    expect(screen.getByText(/hanya bisa dipisah utuh/i)).toBeInTheDocument();
    await user.click(inc('nasi goreng'));

    expect(screen.getByTestId('split-qty-1')).toHaveTextContent('3'); // seluruh baris, bukan 1
    await user.click(dec('nasi goreng'));
    expect(screen.getByTestId('split-qty-1')).toHaveTextContent('0');
  });

  it('allows part of a line once it has been served', async () => {
    const user = userEvent.setup();
    renderSplit({ tab: tab({ lines: [line({ sent: true, prep_status: 'served', ticket_item_id: 9 })] }) });

    expect(screen.queryByText(/hanya bisa dipisah utuh/i)).toBeNull();
    await user.click(inc('nasi goreng'));
    expect(screen.getByTestId('split-qty-1')).toHaveTextContent('1');
  });

  it('emits the chosen lines and the payments once paid', async () => {
    const user = userEvent.setup();
    const { emitted } = renderSplit({ tab: tab({ lines: [line({ id: 1, qty: 2, unit_price: '10000.00' }), line({ id: 2, name: 'Es Teh', qty: 1, unit_price: '5000.00' })] }) });

    await user.click(inc('nasi goreng'));
    await user.click(inc('es teh'));
    await user.click(await screen.findByRole('button', { name: /bayar bagian ini/i }));

    await waitFor(() => expect(emitted().submit).toBeTruthy());
    const [payload] = emitted().submit[0];
    expect(payload.items).toEqual([{ item_id: 1, qty: 1 }, { item_id: 2, qty: 1 }]);
    expect(payload.payments).toHaveLength(1);
    expect(payload.payments[0]).toMatchObject({ method: 'cash' });
    expect(Number(payload.payments[0].amount)).toBe(15000);
  });

  it('refuses to split a tab that already has a payment, and says why', () => {
    renderSplit({ tab: tab({ paid_amount: '5000.00' }) });

    expect(screen.getByRole('alert')).toHaveTextContent(/sudah ada pembayarannya tidak bisa dipisah/i);
    expect(screen.queryByTestId('split-total')).toBeNull();
  });

  it('forgets the previous selection when it is reopened', async () => {
    const user = userEvent.setup();
    const { rerender } = renderSplit();
    await user.click(inc('nasi goreng'));
    expect(screen.getByTestId('split-qty-1')).toHaveTextContent('1');

    await rerender({ open: false });
    await rerender({ open: true });

    expect(screen.getByTestId('split-qty-1')).toHaveTextContent('0');
  });

  it('shows each line\'s own name and quantity on the tab', () => {
    renderSplit({ tab: tab({ lines: [line({ id: 1, name: 'Nasi', qty: 2 }), line({ id: 2, name: 'Teh', qty: 5 })] }) });

    expect(within(screen.getByTestId('split-line-2')).getByText('Teh')).toBeInTheDocument();
    expect(screen.getByTestId('split-line-2')).toHaveTextContent('5 di tab');
  });
});
