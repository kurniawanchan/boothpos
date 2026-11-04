import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';

const tx = (over) => ({
  key: `order:${over.id}`, status: 'completed', created_at: '2026-10-02T03:00:00Z', session_id: 1, total_amount: '100000.00',
  cash_amount: '0.00', noncash_amount: '0.00', payment_methods: [], artist_names: [], unit_count: 1, item_count: 1,
  payment_status: 'fully_paid', paid_amount: '100000.00', balance_amount: '0.00', items_gross: '100000.00', items_net: '100000.00', ...over,
});

const ROWS = [
  tx({ id: 1, order_number: 'A', has_reference: true, artist_names: ['Nekoyama'], payment_state: 'pending' }),
  tx({ id: 2, order_number: 'B', has_reference: false, artist_names: ['Nekoyama'], payment_state: 'pending' }),
  tx({ id: 3, order_number: 'C', has_reference: true, artist_names: ['Yuki'], payment_state: 'verified' }),
  tx({ id: 4, order_number: 'D' }), // payload lama tanpa `has_reference` → dianggap tanpa referensi
];

const setup = (rows = ROWS) => useSalesFilters({ transactions: ref(rows), sessions: ref([]) });
const names = (c) => c.filtered.value.map((r) => r.order_number);

/**
 * 056 (US6) — filter Sale Reference: semua / punya / tanpa. Memakai `has_reference` yang sudah ada di tiap baris,
 * mengikuti pola filter lain (AND antar-filter, dipulihkan dari URL, nilai tak dikenal diabaikan).
 */
describe('useSalesFilters — Sale Reference filter (056 US6)', () => {
  it('"has" keeps only sales with a reference; "none" only those without (a missing flag counts as none)', () => {
    const c = setup();

    c.filters.reference = 'has';
    expect(names(c)).toEqual(['A', 'C']);

    c.filters.reference = 'none';
    expect(names(c)).toEqual(['B', 'D']);

    c.filters.reference = '';
    expect(names(c)).toEqual(['A', 'B', 'C', 'D']);
  });

  it('combines with other filters using AND', () => {
    const c = setup();
    c.filters.reference = 'has';
    c.filters.sellers = ['Nekoyama'];
    expect(names(c)).toEqual(['A']);

    c.filters.paymentState = 'verified';
    expect(names(c)).toEqual([]);
  });

  it('counts as an active filter and is cleared by reset()', () => {
    const c = setup();
    expect(c.activeCount.value).toBe(0);

    c.filters.reference = 'none';
    expect(c.activeCount.value).toBe(1);
    expect(c.hasFilterOtherThanSearch.value).toBe(true);

    c.reset();
    expect(c.filters.reference).toBe('');
    expect(c.activeCount.value).toBe(0);
  });

  it('is written to the address only when set, and restored from it', () => {
    const c = setup();
    expect(c.toQuery()).not.toHaveProperty('ref');

    c.filters.reference = 'has';
    expect(c.toQuery().ref).toBe('has');

    const other = setup();
    other.applyQuery({ ref: 'none' });
    expect(other.filters.reference).toBe('none');
    expect(names(other)).toEqual(['B', 'D']);
  });

  it('ignores an unknown value in the address instead of failing', () => {
    const c = setup();
    c.applyQuery({ ref: 'bogus' });

    expect(c.filters.reference).toBe('');
    expect(names(c)).toEqual(['A', 'B', 'C', 'D']);
  });

  it('sends the page back to 1 when the filter changes', () => {
    const rows = Array.from({ length: 60 }, (_, i) => tx({ id: i + 1, order_number: `R${i + 1}`, has_reference: i % 2 === 0 }));
    const c = setup(rows);
    c.page.value = 2;
    expect(c.currentPage.value).toBe(2);

    c.filters.reference = 'has';

    expect(c.currentPage.value).toBe(1);
  });

  it('makes the summary, cards and shown count follow exactly the filtered rows (SC-008)', () => {
    const c = setup();
    c.filters.reference = 'has';

    expect(c.summary.value.shown).toBe(2);
    expect(c.summary.value.total).toBe(4);
    expect(c.kpis.value.unit_count ?? c.kpis.value.units).toBe(2);
  });
});
