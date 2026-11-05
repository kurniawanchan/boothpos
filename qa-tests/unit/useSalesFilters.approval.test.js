import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';

const tx = (over) => ({
  key: `order:${over.id}`, status: 'completed', created_at: '2026-10-02T03:00:00Z', session_id: 1, total_amount: '100000.00',
  cash_amount: '0.00', noncash_amount: '0.00', payment_methods: [], artist_names: [], unit_count: 1, item_count: 1,
  payment_status: 'fully_paid', paid_amount: '100000.00', balance_amount: '0.00', items_gross: '100000.00', items_net: '100000.00', ...over,
});
const appr = (status, approved = 0, total = 1) => ({ status, total, approved, pending: total - approved, sellers: [] });

const ROWS = [
  tx({ id: 1, order_number: 'A', approval: appr('pending'), artist_names: ['Nekoyama'] }),
  tx({ id: 2, order_number: 'B', approval: appr('partial', 1, 3), artist_names: ['Nekoyama'] }),
  tx({ id: 3, order_number: 'C', approval: appr('full', 1, 1), artist_names: ['Yuki'] }),
  tx({ id: 4, order_number: 'D' }), // payload lama tanpa `approval` → dianggap belum disetujui
  tx({ id: 5, order_number: 'E', status: 'voided', approval: appr('voided', 0, 1) }),
];

const setup = (rows = ROWS) => useSalesFilters({ transactions: ref(rows), sessions: ref([]) });
const names = (c) => c.filtered.value.map((r) => r.order_number);

/**
 * 059 (US3) — filter status persetujuan KESELURUHAN transaksi. Nilainya `approval.status` dari server;
 * filter tidak pernah melihat status per seller.
 */
describe('useSalesFilters — approval filter (059 US3)', () => {
  it('each value keeps only sales whose OVERALL status matches (a missing payload counts as pending)', () => {
    const c = setup();

    c.filters.approval = 'pending';
    expect(names(c)).toEqual(['A', 'D']);

    c.filters.approval = 'partial';
    expect(names(c)).toEqual(['B']);

    c.filters.approval = 'full';
    expect(names(c)).toEqual(['C']);

    c.filters.approval = '';
    expect(names(c)).toEqual(['A', 'B', 'C', 'D', 'E']);
  });

  it('a voided sale never matches a non-empty approval filter', () => {
    const c = setup();
    for (const v of ['pending', 'partial', 'full']) {
      c.filters.approval = v;
      expect(names(c)).not.toContain('E');
    }
  });

  it('a one-seller-of-three sale is Partially Approved, a fully approved single seller is not', () => {
    const c = setup();
    c.filters.approval = 'partial';
    expect(c.filtered.value[0].approval.approved).toBe(1);
    expect(c.filtered.value[0].approval.total).toBe(3);
    expect(names(c)).not.toContain('C');
  });

  it('combines with the other filters using AND', () => {
    const c = setup();
    c.filters.approval = 'partial';
    c.filters.sellers = ['Nekoyama'];
    expect(names(c)).toEqual(['B']);

    c.filters.sellers = ['Yuki'];
    expect(names(c)).toEqual([]);
  });

  it('works with search', () => {
    const c = setup();
    c.filters.approval = 'pending';
    c.search.value = 'D';
    expect(names(c)).toEqual(['D']);
  });

  it('counts as an active filter and is cleared by reset()', () => {
    const c = setup();
    expect(c.activeCount.value).toBe(0);

    c.filters.approval = 'full';
    expect(c.activeCount.value).toBe(1);
    expect(c.hasFilterOtherThanSearch.value).toBe(true);

    c.reset();
    expect(c.filters.approval).toBe('');
    expect(c.activeCount.value).toBe(0);
  });

  it('is written to the address as `appr` only when set, and restored from it', () => {
    const c = setup();
    expect(c.toQuery()).not.toHaveProperty('appr');

    c.filters.approval = 'partial';
    expect(c.toQuery().appr).toBe('partial');

    const other = setup();
    other.applyQuery({ appr: 'full' });
    expect(other.filters.approval).toBe('full');
    expect(names(other)).toEqual(['C']);
  });

  it('ignores an unknown value (including "voided") in the address instead of failing', () => {
    const c = setup();
    c.applyQuery({ appr: 'bogus' });
    expect(c.filters.approval).toBe('');
    c.applyQuery({ appr: 'voided' });
    expect(c.filters.approval).toBe('');
    expect(names(c)).toEqual(['A', 'B', 'C', 'D', 'E']);
  });

  it('sends the page back to 1 when the filter changes', () => {
    const rows = Array.from({ length: 60 }, (_, i) => tx({ id: i + 1, order_number: `R${i + 1}`, approval: appr(i % 2 === 0 ? 'full' : 'pending', 1, 1) }));
    const c = setup(rows);
    c.page.value = 2;
    expect(c.currentPage.value).toBe(2);

    c.filters.approval = 'full';

    expect(c.currentPage.value).toBe(1);
  });

  it('makes the shown count follow exactly the filtered rows (all pages, not just the visible one)', () => {
    const rows = Array.from({ length: 60 }, (_, i) => tx({ id: i + 1, order_number: `R${i + 1}`, approval: appr(i % 3 === 0 ? 'full' : 'pending', 1, 1) }));
    const c = setup(rows);
    c.filters.approval = 'full';

    expect(c.summary.value.shown).toBe(20);
    expect(c.summary.value.total).toBe(60);
  });
});
