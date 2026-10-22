import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import ShiftTable from '../../resources/js/components/session/ShiftTable.vue';

const ROWS = [
  { id: 11, source: 'app', terminal_name: 'Kasir Depan', cashier: { id: 1, name: 'Dewi' }, outlet: { id: 1, name: 'Warkop WJM 3' }, status: 'open', opened_at: '2026-10-22T06:39:00+07:00', closed_at: null, opening_cash: '100000.00', order_count: 3, sales_total: '37400.00', expected_cash: '137400.00', counted_total: null, difference: null },
  { id: 12, source: 'web', terminal_name: null, cashier: { id: 2, name: 'Budi' }, outlet: { id: 1, name: 'Warkop WJM 3' }, status: 'open', opened_at: '2026-10-22T07:00:00+07:00', closed_at: null, opening_cash: '50000.00', order_count: 0, sales_total: '0.00', expected_cash: '50000.00', counted_total: null, difference: null },
];
const CLOSED = [
  { id: 21, source: 'app', terminal_name: 'Kasir Depan', cashier: { id: 1, name: 'Dewi' }, outlet: { id: 1, name: 'Warkop WJM 3' }, status: 'closed', opened_at: '2026-10-21T06:00:00+07:00', closed_at: '2026-10-21T22:00:00+07:00', opening_cash: '100000.00', order_count: 9, sales_total: '900000.00', expected_cash: '1000000.00', counted_total: '995000.00', difference: '-5000.00' },
  { id: 22, source: 'web', terminal_name: null, cashier: { id: 2, name: 'Budi' }, outlet: { id: 1, name: 'Warkop WJM 3' }, status: 'closed', opened_at: '2026-10-21T06:00:00+07:00', closed_at: '2026-10-21T22:00:00+07:00', opening_cash: '0.00', order_count: 0, sales_total: '0.00', expected_cash: '0.00', counted_total: '0.00', difference: '0.00' },
];

describe('ShiftTable (065)', () => {
  it('lists open shifts with cashier, source label (terminal name or Web), opening cash, sales and expected cash', () => {
    render(ShiftTable, { props: { rows: ROWS, mode: 'open' } });

    const app = screen.getByText('Dewi').closest('tr');
    expect(within(app).getByText('Kasir Depan')).toBeInTheDocument();
    expect(within(app).getByText(/Rp\s?37\.400/)).toBeInTheDocument();
    expect(within(app).getByText(/Rp\s?137\.400/)).toBeInTheDocument();
    const web = screen.getByText('Budi').closest('tr');
    expect(within(web).getByText('Web')).toBeInTheDocument();
    expect(screen.queryByRole('columnheader', { name: /selisih|difference/i })).not.toBeInTheDocument();
  });

  it('shows counted cash and the difference for closed shifts, and marks a difference', () => {
    render(ShiftTable, { props: { rows: CLOSED, mode: 'closed' } });

    expect(screen.getByRole('columnheader', { name: /selisih|difference/i })).toBeInTheDocument();
    const short = screen.getByText('Dewi').closest('tr');
    expect(within(short).getByText(/Rp\s?995\.000/)).toBeInTheDocument();
    expect(within(short).getByText(/−\s?Rp\s?5\.000|−Rp\s?5\.000/)).toBeInTheDocument();
    const exact = screen.getByText('Budi').closest('tr');
    expect(within(exact).queryByText(/−/)).not.toBeInTheDocument();
  });

  it('emits select with the shift id when a detail link is clicked', async () => {
    const { emitted } = render(ShiftTable, { props: { rows: ROWS, mode: 'open' } });

    await userEvent.setup().click(screen.getByTestId('shift-detail-12'));

    expect(emitted().select[0]).toEqual([12]);
  });

  it('shows an empty message and an error state with retry', async () => {
    const { emitted, rerender } = render(ShiftTable, { props: { rows: [], mode: 'open' } });
    expect(screen.getByText(/tidak ada shift|no shift is open/i)).toBeInTheDocument();

    await rerender({ rows: [], mode: 'open', error: 'Gagal' });
    await userEvent.setup().click(screen.getByRole('button', { name: /coba lagi|retry/i }));
    expect(emitted().retry).toBeTruthy();
  });
});
