import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';

const row = (over) => ({
  id: 1, status: 'completed', session_id: 7, created_at: '2026-10-03T10:00:00+07:00',
  total_amount: '10000.00', cash_amount: '10000.00', noncash_amount: '0.00', item_count: 1, ...over,
});

const make = (rows) => useSalesFilters({ transactions: ref(rows), sessions: ref([]) });

/** 064 — terminal yang mengambil order: opsi, filter, URL, dan urutan. */
describe('useSalesFilters — terminal (064)', () => {
  const rows = [
    row({ id: 1, terminal_id: 5, terminal_name: 'Kasir Depan' }),
    row({ id: 2, terminal_id: 6, terminal_name: 'Kasir Belakang' }),
    row({ id: 3, terminal_id: 5, terminal_name: 'Kasir Depan' }),
    row({ id: 4, terminal_id: null, terminal_name: null }),
  ];

  it('derives one option per terminal, sorted by name, ignoring rows without a terminal', () => {
    const { terminalOptions } = make(rows);
    expect(terminalOptions.value).toEqual([
      { value: 5, label: 'Kasir Depan' },
      { value: 6, label: 'Kasir Belakang' },
    ].sort((a, b) => a.label.localeCompare(b.label)));
  });

  it('has no options when no row carries a terminal (the filter stays hidden)', () => {
    const { terminalOptions } = make([row({ id: 1 }), row({ id: 2, terminal_id: null, terminal_name: null })]);
    expect(terminalOptions.value).toEqual([]);
  });

  it('filters by terminal id and ANDs with other filters', () => {
    const f = make(rows);
    f.filters.terminals = [5];
    expect(f.sorted.value.map((tx) => tx.id).sort()).toEqual([1, 3]);
    f.filters.terminals = [5, 6];
    expect(f.sorted.value.map((tx) => tx.id).sort()).toEqual([1, 2, 3]);
    expect(f.activeCount.value).toBe(1);
  });

  it('round-trips through the URL and ignores nothing valid', () => {
    const f = make(rows);
    f.filters.terminals = [5, 6];
    expect(f.toQuery().terminal).toBe('5,6');

    const g = make(rows);
    g.applyQuery({ terminal: '6' });
    expect(g.filters.terminals).toEqual(['6']);
    expect(g.sorted.value.map((tx) => tx.id)).toEqual([2]);
  });

  it('an unknown terminal value in the URL simply matches nothing instead of failing', () => {
    const f = make(rows);
    f.applyQuery({ terminal: '999' });
    expect(f.sorted.value).toEqual([]);
  });

  it('reset clears the terminal filter', () => {
    const f = make(rows);
    f.filters.terminals = [5];
    f.reset();
    expect(f.filters.terminals).toEqual([]);
  });

  it('sorts by terminal name with empty names last in either direction', () => {
    const f = make(rows);
    f.sortBy({ key: 'terminal_name', dir: 'asc' });
    expect(f.sorted.value.map((tx) => tx.terminal_name)).toEqual(['Kasir Belakang', 'Kasir Depan', 'Kasir Depan', null]);
    f.sortBy({ key: 'terminal_name', dir: 'desc' });
    expect(f.sorted.value.map((tx) => tx.terminal_name)).toEqual(['Kasir Depan', 'Kasir Depan', 'Kasir Belakang', null]);
  });
});
