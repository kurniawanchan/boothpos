import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';

const row = (over) => ({
  id: 1, status: 'completed', session_id: 7, created_at: '2026-10-03T10:00:00+07:00',
  total_amount: '10000.00', cash_amount: '10000.00', noncash_amount: '0.00', item_count: 1, ...over,
});

describe('useSalesFilters — tab terbuka (028)', () => {
  it('never lists or counts an open tab, even if the API were to send one', () => {
    const transactions = ref([
      row({ id: 1, total_amount: '10000.00', cash_amount: '10000.00' }),
      row({ id: 2, status: 'open', total_amount: '999000.00', cash_amount: '999000.00' }),
    ]);
    const { sorted, summary } = useSalesFilters({ transactions, sessions: ref([]) });

    expect(sorted.value.map((tx) => tx.id)).toEqual([1]);
    expect(summary.value.amount).toBe(10000);
    expect(summary.value.cash).toBe(10000);
    expect(summary.value.total).toBe(1);
  });

  it('keeps open tabs out of the shift cash summary', () => {
    const transactions = ref([
      row({ id: 1, cash_amount: '10000.00' }),
      row({ id: 2, status: 'open', cash_amount: '50000.00' }),
    ]);
    const sessions = ref([{ id: 7, status: 'open', opening_cash: '100000.00', expected_cash: null, closing_cash: null }]);
    const f = useSalesFilters({ transactions, sessions });
    f.filters.sessions = [7];

    expect(f.shiftSummary.value.cashSales).toBe(10000);
    expect(f.shiftSummary.value.expected).toBe(110000);
  });

  it('still excludes voided sales from the totals', () => {
    const transactions = ref([
      row({ id: 1, total_amount: '10000.00' }),
      row({ id: 2, status: 'voided', total_amount: '30000.00' }),
    ]);
    const f = useSalesFilters({ transactions, sessions: ref([]) });
    f.includeVoided.value = true;

    expect(f.summary.value.amount).toBe(10000);
    expect(f.summary.value.voided).toBe(1);
  });
});
