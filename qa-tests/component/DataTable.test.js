import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
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
});
