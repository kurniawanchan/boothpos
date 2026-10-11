import { describe, it, expect } from 'vitest';
import { nextTick } from 'vue';
import { render, screen, fireEvent, within } from '@testing-library/vue';
import DataTable from '../../resources/js/components/ui/DataTable.vue';

// Regression coverage for FR-006/FR-007 (spec 010-split-payment-preorder-reports,
// User Story 3): every data row must carry a hover highlight, and that highlight
// must coexist with — never replace — any other row-level indicator a caller
// applies via a `cell-*` slot (selection state, status color, etc.), since
// DataTable itself owns the <tr> element and callers can only style its cells.
const COLUMNS = [
  { key: 'name', label: 'Name' },
  { key: 'status', label: 'Status' },
];

const ROWS = [
  { id: 1, name: 'Keychain', status: 'active' },
  { id: 2, name: 'Poster', status: 'inactive' },
];

describe('DataTable', () => {
  it('applies the shared hover highlight class to every data row', () => {
    render(DataTable, { props: { columns: COLUMNS, rows: ROWS } });
    const dataRows = screen.getAllByRole('row').slice(1); // skip the header row
    expect(dataRows).toHaveLength(2);
    dataRows.forEach((row) => {
      expect(row).toHaveClass('hover:bg-line-7');
      expect(row).toHaveClass('transition-colors');
    });
  });

  it('keeps the hover class alongside a caller-applied status indicator on the cell', () => {
    render(DataTable, {
      props: { columns: COLUMNS, rows: ROWS, rowKey: 'id' },
      slots: {
        'cell-status': `<template #cell-status="{ row }">
          <span :class="row.status === 'active' ? 'bg-success-bg' : 'bg-line-7'" data-testid="status-badge">{{ row.status }}</span>
        </template>`,
      },
    });

    const dataRows = screen.getAllByRole('row').slice(1);
    // The row itself still carries the hover class even though a cell inside
    // it carries its own independent status-driven class — the two coexist
    // because they live on different elements (tr vs the slotted span).
    dataRows.forEach((row) => {
      expect(row).toHaveClass('hover:bg-line-7');
    });

    const badges = screen.getAllByTestId('status-badge');
    expect(badges[0]).toHaveClass('bg-success-bg');
    expect(badges[1]).toHaveClass('bg-line-7');
  });

  it('does not apply the row hover class to the loading/empty placeholder rows', () => {
    render(DataTable, { props: { columns: COLUMNS, rows: [], loading: true } });
    const rows = screen.getAllByRole('row').slice(1);
    expect(rows).toHaveLength(1);
    expect(rows[0]).not.toHaveClass('hover:bg-line-7');
  });

  // Added for the pre-orders list's "flag rows missing shipping info"
  // feature — an optional per-row class callback, since DataTable owns
  // the <tr> element and a caller can't otherwise style the row itself
  // (only its cells via cell-* slots, per the tests above).
  it('applies the caller-provided rowClass function per row, alongside the shared hover class', () => {
    render(DataTable, {
      props: {
        columns: COLUMNS,
        rows: ROWS,
        rowClass: (row) => (row.status === 'inactive' ? 'bg-warn-bg' : ''),
      },
    });

    const dataRows = screen.getAllByRole('row').slice(1);
    expect(dataRows[0]).not.toHaveClass('bg-warn-bg');
    expect(dataRows[0]).toHaveClass('hover:bg-line-7');
    expect(dataRows[1]).toHaveClass('bg-warn-bg');
    expect(dataRows[1]).toHaveClass('hover:bg-line-7');
  });
});

// 041-back-office-ui-redesign (US2) — pilihan baris, kepala menempel, dan keadaan galat/memuat.
describe('DataTable — pilihan baris', () => {
  const ROWS3 = [
    { id: 1, name: 'A', status: 'x' },
    { id: 2, name: 'B', status: 'y' },
    { id: 3, name: 'C', status: 'z' },
  ];

  it('tanpa selectable tidak ada kolom kotak centang', () => {
    render(DataTable, { props: { columns: COLUMNS, rows: ROWS3 } });
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
  });

  it('selectable menambah kotak di kepala dan di setiap baris', () => {
    render(DataTable, { props: { columns: COLUMNS, rows: ROWS3, selectable: true, selected: [] } });
    expect(screen.getAllByRole('checkbox')).toHaveLength(4);
    expect(screen.getByRole('checkbox', { name: /pilih semua/i })).not.toBeChecked();
  });

  it('memilih satu baris memancarkan daftar id baru dan menandai baris terpilih', async () => {
    const { emitted } = render(DataTable, { props: { columns: COLUMNS, rows: ROWS3, selectable: true, selected: [2] } });
    const boxes = screen.getAllByRole('checkbox', { name: /pilih baris/i });
    expect(boxes[1]).toBeChecked();
    expect(screen.getAllByRole('row')[2]).toHaveClass('bg-mint-50');
    await fireEvent.click(boxes[0]);
    expect(emitted()['update:selected'].at(-1)).toEqual([[2, 1]]);
    await fireEvent.click(boxes[1]);
    expect(emitted()['update:selected'].at(-1)).toEqual([[]]);
  });

  it('kotak kepala memilih semua baris di halaman ini dan membatalkannya, tanpa menyentuh id di luar halaman', async () => {
    const { emitted, rerender } = render(DataTable, { props: { columns: COLUMNS, rows: ROWS3, selectable: true, selected: [99] } });
    const head = screen.getByRole('checkbox', { name: /pilih semua/i });
    await fireEvent.click(head);
    expect(emitted()['update:selected'].at(-1)).toEqual([[99, 1, 2, 3]]);
    await rerender({ selected: [99, 1, 2, 3] });
    expect(head).toBeChecked();
    await fireEvent.click(head);
    expect(emitted()['update:selected'].at(-1)).toEqual([[99]]);
  });

  it('sebagian terpilih membuat kotak kepala indeterminate (bukan tercentang)', async () => {
    render(DataTable, { props: { columns: COLUMNS, rows: ROWS3, selectable: true, selected: [1] } });
    await nextTick(); // properti DOM `indeterminate` diatur setelah render (flush post)
    const head = screen.getByRole('checkbox', { name: /pilih semua/i });
    expect(head.indeterminate).toBe(true);
    expect(head).not.toBeChecked();
  });

  it('rowLabel memberi nama aksesibel khusus pada kotak pilih setiap baris', () => {
    render(DataTable, { props: { columns: COLUMNS, rows: ROWS3, selectable: true, rowLabel: (r) => `Pilih ${r.name}` } });
    expect(screen.getByRole('checkbox', { name: 'Pilih B' })).toBeInTheDocument();
  });

  it('baris pada baris kosong/memuat memakai colspan yang menghitung kolom pilihan', () => {
    const { container } = render(DataTable, { props: { columns: COLUMNS, rows: [], selectable: true } });
    expect(container.querySelector('tbody td').getAttribute('colspan')).toBe('3');
  });
});

describe('DataTable — kepala menempel dan keadaan', () => {
  it('stickyHeader membuat sel kepala menempel di atas', () => {
    const { container } = render(DataTable, { props: { columns: COLUMNS, rows: ROWS, stickyHeader: true } });
    container.querySelectorAll('thead th').forEach((th) => expect(th).toHaveClass('sticky'));
  });

  it('tanpa stickyHeader kepala tidak menempel', () => {
    const { container } = render(DataTable, { props: { columns: COLUMNS, rows: ROWS } });
    container.querySelectorAll('thead th').forEach((th) => expect(th).not.toHaveClass('sticky'));
  });

  it('keadaan galat menampilkan pesan + coba lagi dan menyembunyikan baris dan footer', async () => {
    const { emitted } = render(DataTable, {
      props: { columns: COLUMNS, rows: ROWS, error: 'Server tidak merespons' },
      slots: { footer: '<tr><td>Total</td></tr>' },
    });
    expect(screen.getByRole('alert')).toHaveTextContent('Server tidak merespons');
    expect(screen.queryByText('Keychain')).toBeNull();
    expect(screen.queryByText('Total')).toBeNull();
    await fireEvent.click(within(screen.getByRole('alert')).getByRole('button', { name: /coba lagi/i }));
    expect(emitted().retry).toHaveLength(1);
  });

  it('keadaan memuat berstatus sibuk dan tidak menampilkan baris', () => {
    render(DataTable, { props: { columns: COLUMNS, rows: ROWS, loading: true } });
    expect(screen.getByRole('status')).toHaveAttribute('aria-busy', 'true');
    expect(screen.queryByText('Keychain')).toBeNull();
  });
});

