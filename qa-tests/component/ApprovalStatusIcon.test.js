import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/vue';
import ApprovalStatusIcon from '../../resources/js/components/sales/ApprovalStatusIcon.vue';

const PARTIAL = {
  status: 'partial', total: 3, approved: 2, pending: 1,
  sellers: [
    { artist_id: 1, name: 'Seller A', status: 'approved', stale: false, approver_name: 'Budi', method: 'draw', approved_at: '2026-11-05T04:20:00Z', on_behalf: false },
    { artist_id: 2, name: 'Seller B', status: 'approved', stale: false, approver_name: 'Sari', method: 'stamp', approved_at: '2026-11-05T05:00:00Z', on_behalf: true },
    { artist_id: 3, name: 'Seller C', status: 'pending', stale: false, approver_name: null, method: null, approved_at: null, on_behalf: null },
  ],
};

const mount = (approval) => render(ApprovalStatusIcon, { props: { approval } });

describe('ApprovalStatusIcon (059 US3)', () => {
  it('renders nothing when the row has no approval summary', () => {
    mount(null);
    expect(screen.queryByTestId('approval-icon')).toBeNull();
  });

  it.each([
    ['pending', 'ph-clock'],
    ['partial', 'ph-circle-half'],
    ['full', 'ph-check-circle'],
    ['voided', 'ph-prohibit'],
  ])('shows a distinct small icon for %s', (status, icon) => {
    mount({ status, total: 1, approved: status === 'full' ? 1 : 0, pending: 0, sellers: [] });
    const button = screen.getByTestId('approval-icon');
    expect(button).toHaveAttribute('data-status', status);
    expect(button.querySelector('i')).toHaveClass(icon);
  });

  it('has an accessible name carrying the overall status', () => {
    mount(PARTIAL);
    expect(screen.getByRole('button', { name: /sebagian disetujui/i })).toBeInTheDocument();
  });

  it('is closed by default and never calls the network (no props beyond the row summary)', () => {
    mount(PARTIAL);
    expect(screen.queryByTestId('approval-popover')).toBeNull();
  });

  it('click opens the popover with overall status, counts, progress and every seller', async () => {
    mount(PARTIAL);
    await fireEvent.click(screen.getByTestId('approval-icon'));

    const pop = await screen.findByTestId('approval-popover');
    expect(pop).toHaveTextContent('Persetujuan Transaksi: Sebagian Disetujui');
    expect(screen.getByTestId('approval-popover-counts')).toHaveTextContent('3 seller');
    expect(screen.getByTestId('approval-popover-counts')).toHaveTextContent('2 menyetujui');
    expect(screen.getByTestId('approval-popover-counts')).toHaveTextContent('1 menunggu');
    expect(screen.getByTestId('approval-popover-progress')).toHaveTextContent('2/3');

    const list = screen.getByTestId('approval-popover-sellers');
    const a = within(list).getByText('Seller A').closest('li');
    expect(a).toHaveTextContent('Disetujui');
    expect(a).toHaveTextContent('Budi');
    expect(a).toHaveTextContent('Gambar tanda tangan');
    expect(within(list).getByText('Seller B').closest('li')).toHaveTextContent('Stempel Disetujui');
    const c = within(list).getByText('Seller C').closest('li');
    expect(c).toHaveTextContent('Menunggu persetujuan');
    expect(c).not.toHaveTextContent('Budi');
  });

  it('Escape closes it and returns focus to the icon', async () => {
    mount(PARTIAL);
    const button = screen.getByTestId('approval-icon');
    await fireEvent.click(button);
    await screen.findByTestId('approval-popover');

    await fireEvent.keyDown(window, { key: 'Escape' });

    expect(screen.queryByTestId('approval-popover')).toBeNull();
    expect(button).toHaveFocus();
  });

  it('a second click on the icon closes it', async () => {
    mount(PARTIAL);
    await fireEvent.click(screen.getByTestId('approval-icon'));
    await screen.findByTestId('approval-popover');
    await fireEvent.click(screen.getByTestId('approval-icon'));
    expect(screen.queryByTestId('approval-popover')).toBeNull();
  });

  it('a click outside closes it', async () => {
    mount(PARTIAL);
    await fireEvent.click(screen.getByTestId('approval-icon'));
    await screen.findByTestId('approval-popover');
    await fireEvent.pointerDown(document.body);
    expect(screen.queryByTestId('approval-popover')).toBeNull();
  });

  it('a voided sale shows only its status, no counts or sellers', async () => {
    mount({ status: 'voided', total: 2, approved: 1, pending: 1, sellers: PARTIAL.sellers });
    await fireEvent.click(screen.getByTestId('approval-icon'));
    const pop = await screen.findByTestId('approval-popover');
    expect(pop).toHaveTextContent('Dibatalkan');
    expect(screen.queryByTestId('approval-popover-sellers')).toBeNull();
  });
});
