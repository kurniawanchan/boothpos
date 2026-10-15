import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import PreorderDuplicateResultModal from '../../resources/js/components/preorder/PreorderDuplicateResultModal.vue';

const RESULTS = [
  { source_id: 1, source_number: 'PO-0001', status: 'created', preorder: { id: 11, preorder_number: 'PO-0011' } },
  { source_id: 2, source_number: 'PO-0002', status: 'failed', error: 'Barang "Gantungan" sudah tidak bisa dijual' },
  { source_id: 3, source_number: 'PO-0003', status: 'created', preorder: { id: 13, preorder_number: 'PO-0013' } },
];

describe('PreorderDuplicateResultModal (027 US2)', () => {
  it('lists source → new number pairs and the failures with their reasons', () => {
    render(PreorderDuplicateResultModal, { props: { open: true, results: RESULTS } });

    const created = screen.getByTestId('duplicate-created');
    expect(within(created).getByText('PO-0001')).toBeInTheDocument();
    expect(within(created).getByRole('button', { name: 'PO-0011' })).toBeInTheDocument();
    expect(within(created).getByRole('button', { name: 'PO-0013' })).toBeInTheDocument();

    const failed = screen.getByTestId('duplicate-failed');
    expect(failed).toHaveTextContent('PO-0002');
    expect(failed).toHaveTextContent('sudah tidak bisa dijual');
  });

  it('hides the failure section when everything succeeded, and vice versa', () => {
    const { unmount } = render(PreorderDuplicateResultModal, { props: { open: true, results: [RESULTS[0]] } });
    expect(screen.queryByTestId('duplicate-failed')).not.toBeInTheDocument();
    unmount();

    render(PreorderDuplicateResultModal, { props: { open: true, results: [RESULTS[1]] } });
    expect(screen.queryByTestId('duplicate-created')).not.toBeInTheDocument();
  });

  it('emits open with the new pre-order id when its number is clicked', async () => {
    const user = userEvent.setup();
    const { emitted } = render(PreorderDuplicateResultModal, { props: { open: true, results: RESULTS } });

    await user.click(screen.getByRole('button', { name: 'PO-0013' }));

    expect(emitted().open[0]).toEqual([13]);
  });

  it('emits close from the footer button and from Escape', async () => {
    const user = userEvent.setup();
    const { emitted } = render(PreorderDuplicateResultModal, { props: { open: true, results: RESULTS } });

    await user.click(screen.getByRole('button', { name: 'Tutup' }));
    expect(emitted().close).toHaveLength(1);

    await user.keyboard('{Escape}');
    expect(emitted().close.length).toBeGreaterThanOrEqual(2);
  });
});
