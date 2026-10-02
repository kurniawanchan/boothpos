import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import PreorderSplitModal from '../../resources/js/components/preorder/PreorderSplitModal.vue';
import { splitPreorder } from '../../resources/js/api/preorders';

vi.mock('../../resources/js/api/preorders', () => ({ splitPreorder: vi.fn() }));

const PREORDER = {
  id: 7,
  preorder_number: 'PO-0007',
  subtotal: '450000.00',
  shipping_cost: '5000.00',
  discount: '10000.00',
  items: [
    { id: 101, name_snapshot: 'Poster A', sku_snapshot: 'AAA00001', sell_price: '100000.00', qty: 4, artist_name: 'Artist A' },
    { id: 102, name_snapshot: 'Stiker B', sku_snapshot: 'BBB00001', sell_price: '25000.00', qty: 2, artist_name: 'Artist A' },
  ],
};

function qtyInput(name) {
  return screen.getByLabelText(`Unit ${name} yang dipindah`);
}

describe('PreorderSplitModal (027 US3)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('keeps confirm disabled until at least one unit moves and one stays', async () => {
    const user = userEvent.setup();
    render(PreorderSplitModal, { props: { open: true, preorder: PREORDER } });
    const confirm = screen.getByTestId('split-confirm');

    expect(confirm).toBeDisabled(); // belum ada yang dipindah

    await user.clear(qtyInput('Poster A'));
    await user.type(qtyInput('Poster A'), '1');
    await user.tab();
    expect(confirm).toBeEnabled();

    // memindahkan SEMUA unit → tak ada yang tersisa
    await user.clear(qtyInput('Poster A'));
    await user.type(qtyInput('Poster A'), '4');
    await user.clear(qtyInput('Stiker B'));
    await user.type(qtyInput('Stiker B'), '2');
    await user.tab();
    expect(confirm).toBeDisabled();
    expect(screen.getByText(/minimal satu unit/i)).toBeInTheDocument();
  });

  it('clamps typed quantities to 0..line quantity', async () => {
    const user = userEvent.setup();
    render(PreorderSplitModal, { props: { open: true, preorder: PREORDER } });

    await user.clear(qtyInput('Stiker B'));
    await user.type(qtyInput('Stiker B'), '9');
    await user.tab();
    expect(qtyInput('Stiker B')).toHaveValue(2);

    await user.clear(qtyInput('Stiker B'));
    await user.type(qtyInput('Stiker B'), '-3');
    await user.tab();
    expect(qtyInput('Stiker B')).toHaveValue(0);
  });

  it('previews both sides live while the quantities change', async () => {
    const user = userEvent.setup();
    render(PreorderSplitModal, { props: { open: true, preorder: PREORDER } });

    await user.clear(qtyInput('Poster A'));
    await user.type(qtyInput('Poster A'), '1');
    await user.tab();

    // pindah 1×100.000; tersisa subtotal 350.000, total 350.000 + 5.000 − 10.000 = 345.000
    expect(screen.getByTestId('split-preview-moves')).toHaveTextContent('100.000');
    expect(screen.getByTestId('split-preview-stays')).toHaveTextContent('350.000');
    expect(screen.getByTestId('split-preview-stays')).toHaveTextContent('345.000');
  });

  it('warns when the discount would exceed what remains on the original', async () => {
    const user = userEvent.setup();
    const bigDiscount = { ...PREORDER, subtotal: '450000.00', shipping_cost: '0.00', discount: '400000.00' };
    render(PreorderSplitModal, { props: { open: true, preorder: bigDiscount } });
    expect(screen.queryByTestId('split-discount-warning')).not.toBeInTheDocument();

    await user.clear(qtyInput('Poster A'));
    await user.type(qtyInput('Poster A'), '4'); // sisa 50.000 < diskon 400.000
    await user.tab();

    expect(await screen.findByTestId('split-discount-warning')).toBeInTheDocument();
  });

  it('submits only the lines that move and emits done with the server result', async () => {
    const user = userEvent.setup();
    const result = { original: { id: 7 }, created: [{ id: 8, preorder_number: 'PO-0008' }] };
    splitPreorder.mockResolvedValue(result);
    const { emitted } = render(PreorderSplitModal, { props: { open: true, preorder: PREORDER } });

    await user.clear(qtyInput('Poster A'));
    await user.type(qtyInput('Poster A'), '2');
    await user.tab();
    await user.click(screen.getByTestId('split-confirm'));

    await waitFor(() => expect(splitPreorder).toHaveBeenCalledWith(7, { mode: 'items', items: [{ item_id: 101, qty: 2 }] }));
    await waitFor(() => expect(emitted().done[0]).toEqual([result]));
  });

  it('shows a 422 message inline and stays open, but leaves 409 to the global toast', async () => {
    const user = userEvent.setup();
    const { emitted } = render(PreorderSplitModal, { props: { open: true, preorder: PREORDER } });
    await user.clear(qtyInput('Poster A'));
    await user.type(qtyInput('Poster A'), '1');
    await user.tab();

    splitPreorder.mockRejectedValueOnce({ status: 422, message: 'Tidak bisa memindahkan lebih dari 4 unit' });
    await user.click(screen.getByTestId('split-confirm'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Tidak bisa memindahkan lebih dari 4 unit');

    splitPreorder.mockRejectedValueOnce({ status: 409, message: 'sudah ada pembayaran' });
    await user.click(screen.getByTestId('split-confirm'));
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    expect(emitted().done).toBeUndefined();
  });

  it('resets the quantities when reopened for another pre-order', async () => {
    const user = userEvent.setup();
    const { rerender } = render(PreorderSplitModal, { props: { open: true, preorder: PREORDER } });
    await user.clear(qtyInput('Poster A'));
    await user.type(qtyInput('Poster A'), '3');
    await user.tab();

    await rerender({ open: true, preorder: { ...PREORDER, id: 9, preorder_number: 'PO-0009' } });

    expect(qtyInput('Poster A')).toHaveValue(0);
  });

  describe('split by seller (027 US4)', () => {
    const MULTI = {
      ...PREORDER,
      items: [
        { ...PREORDER.items[0], artist_id: 1 },
        { ...PREORDER.items[1], artist_id: 2 },
      ],
    };

    it('offers "Pisah per penjual" only when the order has two or more distinct sellers', () => {
      const { unmount } = render(PreorderSplitModal, { props: { open: true, preorder: MULTI } });
      expect(screen.getByTestId('split-by-seller')).toBeInTheDocument();
      unmount();

      const single = { ...PREORDER, items: PREORDER.items.map((i) => ({ ...i, artist_id: 1 })) };
      render(PreorderSplitModal, { props: { open: true, preorder: single } });
      expect(screen.queryByTestId('split-by-seller')).not.toBeInTheDocument();
    });

    it('submits { mode: "by_seller" } without needing any quantity, and emits done', async () => {
      const user = userEvent.setup();
      const result = { original: { id: 7 }, created: [{ id: 8, preorder_number: 'PO-0008' }] };
      splitPreorder.mockResolvedValue(result);
      const { emitted } = render(PreorderSplitModal, { props: { open: true, preorder: MULTI } });

      expect(screen.getByTestId('split-confirm')).toBeDisabled(); // mode manual tetap butuh unit
      await user.click(screen.getByTestId('split-by-seller'));

      await waitFor(() => expect(splitPreorder).toHaveBeenCalledWith(7, { mode: 'by_seller' }));
      await waitFor(() => expect(emitted().done[0]).toEqual([result]));
    });
  });
});
