import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';

const tx = (over) => ({
  key: `order:${over.id}`, status: 'completed', created_at: '2026-10-02T03:00:00Z', session_id: 1, total_amount: '100000.00',
  cash_amount: '0.00', noncash_amount: '0.00', payment_methods: [], artist_names: [], unit_count: 1, item_count: 1,
  payment_status: 'fully_paid', paid_amount: '100000.00', balance_amount: '0.00', ...over,
});

const ROWS = [
  tx({ id: 1, order_number: 'A', payment_status: 'fully_paid' }),
  tx({ id: 2, order_number: 'B', payment_status: 'partially_paid', paid_amount: '40000.00', balance_amount: '60000.00', cash_amount: '40000.00' }),
  tx({ id: 3, order_number: 'C', payment_status: 'partially_paid', paid_amount: '10000.00', balance_amount: '90000.00' }),
  tx({ id: 4, order_number: 'D', status: 'voided', payment_status: 'partially_paid', paid_amount: '10000.00', balance_amount: '90000.00' }),
];

function setup(rows = ROWS, sessions = []) {
  return useSalesFilters({ transactions: ref(rows), sessions: ref(sessions) });
}

/**
 * 028-partial-split-payment (US5) — filter status pembayaran, total sisa tagihan, dan
 * kas shift yang dihitung dari tempat uang diterima.
 */
describe('useSalesFilters — payment status (028 US5)', () => {
  it('filters by payment status', () => {
    const { filters, filtered } = setup();
    filters.settlement = 'partially_paid';

    expect(filtered.value.map((r) => r.order_number)).toEqual(['B', 'C', 'D']);

    filters.settlement = 'fully_paid';
    expect(filtered.value.map((r) => r.order_number)).toEqual(['A']);
  });

  it('treats rows from an older payload (no payment_status) as fully paid', () => {
    const legacy = [{ ...tx({ id: 9, order_number: 'OLD' }), payment_status: undefined, balance_amount: undefined }];
    const { filters, filtered } = setup(legacy);

    filters.settlement = 'fully_paid';
    expect(filtered.value).toHaveLength(1);
    filters.settlement = 'partially_paid';
    expect(filtered.value).toHaveLength(0);
  });

  it('counts the filter as active and clears it on reset', () => {
    const { filters, activeCount, reset } = setup();
    filters.settlement = 'unpaid';
    expect(activeCount.value).toBe(1);

    reset();
    expect(filters.settlement).toBe('');
    expect(activeCount.value).toBe(0);
  });

  it('sums the outstanding balance of the listed sales and never counts voided ones', () => {
    const { summary, filters } = setup();

    expect(summary.value.outstanding).toBe(150000); // B 60.000 + C 90.000, tanpa D (batal)

    filters.settlement = 'partially_paid';
    expect(summary.value.outstanding).toBe(150000);
    filters.settlement = 'fully_paid';
    expect(summary.value.outstanding).toBe(0);
  });

  it('keeps the filter in the URL query and restores it, ignoring unknown values', () => {
    const { filters, toQuery, applyQuery } = setup();
    filters.settlement = 'partially_paid';

    expect(toQuery().settle).toBe('partially_paid');

    filters.settlement = '';
    applyQuery({ settle: 'partially_paid' });
    expect(filters.settlement).toBe('partially_paid');

    applyQuery({ settle: 'bogus' });
    expect(filters.settlement).toBe('');
  });
});

describe('useSalesFilters — shift cash from the receiving shift (028 US5)', () => {
  const OPEN = { id: 1, status: 'open', opening_cash: '50000.00', closing_cash: null, expected_cash: null };

  it('uses session.cash_received for an open shift when the server sends it', () => {
    const { filters, shiftSummary } = setup(ROWS, [{ ...OPEN, cash_received: '300000.00' }]);
    filters.sessions = [1];

    expect(shiftSummary.value.cashSales).toBe(300000);
    expect(shiftSummary.value.expected).toBe(350000);
  });

  it('falls back to summing the rows when the payload has no cash_received', () => {
    const { filters, shiftSummary } = setup(ROWS, [OPEN]);
    filters.sessions = [1];

    expect(shiftSummary.value.cashSales).toBe(40000); // hanya baris B yang membawa tunai (D batal dikecualikan)
  });

  it('shows the stored expected cash for a closed shift regardless', () => {
    const closed = { id: 1, status: 'closed', opening_cash: '0.00', closing_cash: '95000.00', expected_cash: '100000.00', cash_received: '100000.00' };
    const { filters, shiftSummary } = setup(ROWS, [closed]);
    filters.sessions = [1];

    expect(shiftSummary.value.expected).toBe(100000);
    expect(shiftSummary.value.difference).toBe(-5000);
  });
});
