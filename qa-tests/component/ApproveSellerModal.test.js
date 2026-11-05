import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ApproveSellerModal from '../../resources/js/components/sales/ApproveSellerModal.vue';
import { useAuthStore } from '../../resources/js/stores/auth';

const SELLER = {
  artist_id: 5, artist_name: 'Seller Alfa', subtotal: '13000.00', items_hash: 'v1:abc',
  items: [{ name: 'Indomie Goreng', qty: 2, line_total: '10000.00' }, { name: 'Es Teh', qty: 1, line_total: '3000.00' }],
};

function mount(extra = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 9, name: 'Budi Penyetuju', role: 'Cashier', menu_keys: ['sales'] };
  return render(ApproveSellerModal, { props: { open: true, seller: SELLER, ...extra }, global: { plugins: [pinia] } });
}

describe('ApproveSellerModal (059)', () => {
  it('offers the three methods', () => {
    mount();
    expect(screen.getByTestId('method-draw')).toBeInTheDocument();
    expect(screen.getByTestId('method-upload')).toBeInTheDocument();
    expect(screen.getByTestId('method-stamp')).toBeInTheDocument();
  });

  it('stamp: needs a confirmation step that names the seller, item count and amount; nothing is sent before it', async () => {
    const { emitted } = mount();
    await fireEvent.click(screen.getByTestId('method-stamp'));
    expect(screen.getByTestId('approval-stamp')).toBeInTheDocument();

    await fireEvent.click(screen.getByTestId('approval-continue'));
    const step = screen.getByTestId('approval-confirm-step');
    expect(step).toHaveTextContent('Seller Alfa');
    expect(step).toHaveTextContent('3 item');
    expect(step).toHaveTextContent('13.000');
    expect(emitted().submit).toBeUndefined();

    await fireEvent.click(screen.getByTestId('approval-confirm'));
    await waitFor(() => expect(emitted().submit).toHaveLength(1));
    expect(emitted().submit[0][0]).toEqual({ method: 'stamp', image: null });
  });

  it('back from the confirmation returns to the method choice without sending anything', async () => {
    const { emitted } = mount();
    await fireEvent.click(screen.getByTestId('method-stamp'));
    await fireEvent.click(screen.getByTestId('approval-continue'));
    await fireEvent.click(screen.getByRole('button', { name: /kembali/i }));

    expect(screen.queryByTestId('approval-confirm-step')).toBeNull();
    expect(screen.getByTestId('approval-continue')).toBeInTheDocument();
    expect(emitted().submit).toBeUndefined();
  });

  it('cancel emits close and never submit', async () => {
    const { emitted } = mount();
    await fireEvent.click(screen.getByRole('button', { name: /^batal$/i }));
    expect(emitted().close).toHaveLength(1);
    expect(emitted().submit).toBeUndefined();
  });

  it('draw: refuses to continue without a signature', async () => {
    mount();
    await fireEvent.click(screen.getByTestId('approval-continue'));
    expect(screen.getByTestId('approval-error')).toHaveTextContent(/gambar tanda tangan/i);
    expect(screen.queryByTestId('approval-confirm-step')).toBeNull();
  });

  it('upload: refuses without a file, a wrong type, and an oversize file; accepts a PNG', async () => {
    const { emitted } = mount();
    await fireEvent.click(screen.getByTestId('method-upload'));

    await fireEvent.click(screen.getByTestId('approval-continue'));
    expect(screen.getByTestId('approval-error')).toHaveTextContent(/pilih gambar/i);

    const input = screen.getByTestId('approval-file');
    await fireEvent.update(input); // no-op guard
    const pick = async (file) => {
      Object.defineProperty(input, 'files', { value: [file], configurable: true });
      await fireEvent.change(input);
    };

    await pick(new File(['x'], 'ttd.gif', { type: 'image/gif' }));
    expect(screen.getByTestId('approval-error')).toHaveTextContent(/png atau jpg/i);

    await pick(new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'besar.png', { type: 'image/png' }));
    expect(screen.getByTestId('approval-error')).toHaveTextContent(/5 MB/);

    const good = new File(['png'], 'ttd.png', { type: 'image/png' });
    await pick(good);
    await fireEvent.click(screen.getByTestId('approval-continue'));
    await fireEvent.click(screen.getByTestId('approval-confirm'));

    await waitFor(() => expect(emitted().submit).toHaveLength(1));
    expect(emitted().submit[0][0]).toEqual({ method: 'upload', image: good });
  });

  it('shows the on-behalf notice only when approving for someone else', () => {
    mount({ onBehalf: true });
    expect(screen.getByTestId('on-behalf-note')).toHaveTextContent('Seller Alfa');
  });

  it('does not show the on-behalf notice for the seller themselves', () => {
    mount({ onBehalf: false });
    expect(screen.queryByTestId('on-behalf-note')).toBeNull();
  });

  it('shows a server error and keeps the dialog on the same step', () => {
    mount({ serverError: 'Hanya gambar PNG atau JPG yang diterima.' });
    expect(screen.getByTestId('approval-error')).toHaveTextContent(/png atau jpg/i);
  });
});
