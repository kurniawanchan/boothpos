import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia } from 'pinia';
import DataTable from '../../resources/js/components/ui/DataTable.vue';

/** 065 — slot opsional `header-<key>`: tanpa slot, kepala kolom tetap label seperti sebelumnya. */
const columns = [{ key: 'a', label: 'Kolom A' }, { key: 'b', label: 'Kolom B', sortable: true }];

describe('DataTable header slot (065)', () => {
  it('renders the plain label when no header slot is given (existing tables unchanged)', () => {
    render(DataTable, { props: { columns, rows: [] }, global: { plugins: [createPinia()] } });
    expect(screen.getByText('Kolom A')).toBeInTheDocument();
    expect(screen.getByText('Kolom B')).toBeInTheDocument();
  });

  it('renders the slot content for just that column and keeps the others', () => {
    render(DataTable, {
      props: { columns, rows: [] },
      slots: { 'header-a': '<input type="checkbox" aria-label="pilih semua" />' },
      global: { plugins: [createPinia()] },
    });
    expect(screen.getByLabelText('pilih semua')).toBeInTheDocument();
    expect(screen.queryByText('Kolom A')).not.toBeInTheDocument();
    expect(screen.getByText('Kolom B')).toBeInTheDocument();
  });
});
