import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import CashMovementsTable from '../../resources/js/components/session/CashMovementsTable.vue';

const ROWS = [
  { id: 1, direction: 'out', reason_code: 'petty_cash', reason_label: 'Uang kecil / belanja', reason_note: 'Belanja bahan', amount: '50000.00', created_by_name: 'Dewi', created_at: '2026-10-22T10:00:00+07:00', source: 'app', terminal_name: 'Kasir Depan' },
  { id: 2, direction: 'in', reason_code: 'float_topup', reason_label: 'Tambah modal', reason_note: null, amount: '20000.00', created_by_name: 'Budi', created_at: '2026-10-22T09:00:00+07:00', source: 'web', terminal_name: null },
];

describe('CashMovementsTable (065)', () => {
  it('shows direction, reason (with note), amount, user and source', () => {
    render(CashMovementsTable, { props: { rows: ROWS } });

    const out = screen.getByText('Uang kecil / belanja').closest('tr');
    expect(within(out).getByText(/kas keluar|cash out/i)).toBeInTheDocument();
    expect(within(out).getByText('Belanja bahan')).toBeInTheDocument();
    expect(within(out).getByText(/Rp\s?50\.000/)).toBeInTheDocument();
    expect(within(out).getByText('Dewi')).toBeInTheDocument();
    expect(within(out).getByText('Kasir Depan')).toBeInTheDocument();
    const inn = screen.getByText('Tambah modal').closest('tr');
    expect(within(inn).getByText(/kas masuk|cash in/i)).toBeInTheDocument();
    expect(within(inn).getByText('Web')).toBeInTheDocument();
  });

  it('hides the source column inside a single shift detail', () => {
    render(CashMovementsTable, { props: { rows: ROWS, showShift: false } });

    expect(screen.queryByRole('columnheader', { name: /sumber|source/i })).not.toBeInTheDocument();
  });

  it('shows an empty message', () => {
    render(CashMovementsTable, { props: { rows: [] } });

    expect(screen.getByText(/belum ada kas|no cash in or out/i)).toBeInTheDocument();
  });
});
