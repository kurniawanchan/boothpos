import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import userEvent from '@testing-library/user-event';
import PosPaymentModal from '../../resources/js/components/payment/PosPaymentModal.vue';

vi.mock('../../resources/js/api/payments', () => ({
  listPaymentChannels: vi.fn().mockResolvedValue({ data: [] }),
  uploadPaymentProof: vi.fn(),
}));

function renderModal(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(PosPaymentModal, {
    props: {
      open: true,
      lines: [{ key: 1, name: 'Poster', qty: 4, lineTotal: '100000.00' }],
      subtotal: '100000.00', discountAmount: '0.00', total: '100000.00',
      ...props,
    },
    global: { plugins: [pinia] },
  });
}

async function typeCash(value) {
  const user = userEvent.setup();
  const input = screen.getByLabelText(/uang diterima/i);
  await user.clear(input);
  await user.type(input, String(value));
  return user;
}

/**
 * 028-partial-split-payment (US5) — checkout POS: penjualan boleh selesai dengan
 * sisa tagihan hanya bila ada pelanggan, dan SELALU lewat konfirmasi.
 */
describe('PosPaymentModal — partial checkout (028 US5)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('asks for confirmation naming the customer and the remaining balance before completing', async () => {
    const { emitted } = renderModal({ customerName: 'Budi' });
    const user = await typeCash(40000);

    await user.click(await screen.findByTestId('finish-partial'));

    const dialog = await screen.findByRole('dialog', { name: /selesaikan penjualan dengan sisa tagihan/i });
    expect(dialog).toHaveTextContent('Budi');
    expect(dialog).toHaveTextContent('60.000');
    expect(emitted().submit).toBeUndefined(); // belum ada yang dikirim
  });

  it('emits submit with the entries only after the confirmation is accepted', async () => {
    const { emitted } = renderModal({ customerName: 'Budi' });
    const user = await typeCash(40000);
    await user.click(await screen.findByTestId('finish-partial'));

    const dialog = await screen.findByRole('dialog', { name: /selesaikan penjualan dengan sisa tagihan/i });
    await user.click(within(dialog).getByRole('button', { name: /selesaikan penjualan$/i }));

    await waitFor(() => expect(emitted().submit).toHaveLength(1));
    expect(emitted().submit[0][0]).toEqual([expect.objectContaining({ method: 'cash', amount: '40000.00' })]);
  });

  it('sends nothing when the confirmation is cancelled', async () => {
    const { emitted } = renderModal({ customerName: 'Budi' });
    const user = await typeCash(40000);
    await user.click(await screen.findByTestId('finish-partial'));

    const dialog = await screen.findByRole('dialog', { name: /selesaikan penjualan dengan sisa tagihan/i });
    await user.click(within(dialog).getByRole('button', { name: /batal/i }));

    await waitFor(() => expect(screen.queryByRole('dialog', { name: /selesaikan penjualan dengan sisa tagihan/i })).not.toBeInTheDocument());
    expect(emitted().submit).toBeUndefined();
  });

  it('without a customer offers no partial finish and shows the hint', async () => {
    renderModal({ customerName: '' });
    await typeCash(40000);

    expect(screen.queryByTestId('finish-partial')).not.toBeInTheDocument();
    expect(screen.getByTestId('partial-needs-customer')).toBeInTheDocument();
  });

  it('a payment that covers the total still goes straight through without any confirmation', async () => {
    const { emitted } = renderModal({ customerName: 'Budi' });
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: /konfirmasi.*transaksi|simpan transaksi/i }));

    await waitFor(() => expect(emitted().submit).toHaveLength(1));
    expect(screen.queryByRole('dialog', { name: /selesaikan penjualan dengan sisa tagihan/i })).not.toBeInTheDocument();
  });
});
