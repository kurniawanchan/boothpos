import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';

const tx = (over) => ({
  key: `order:${over.id}`, status: 'completed', created_at: '2026-10-02T03:00:00Z', session_id: 1, payment_methods: [],
  payment_status: 'fully_paid', paid_amount: '0.00', balance_amount: '0.00', cash_amount: '0.00', noncash_amount: '0.00', ...over,
});
const part = (units, gross, net = gross) => ({ units, gross, net });

// A 50.000 + B 70.000 (campuran), B saja, A saja, dan satu transaksi batal milik A.
const ROWS = [
  tx({ id: 1, order_number: 'MIX', artist_names: ['A', 'B'], total_amount: '120000.00', unit_count: 3, items_gross: '120000.00', items_net: '120000.00',
    seller_items: { A: part(1, '50000.00'), B: part(2, '70000.00') } }),
  tx({ id: 2, order_number: 'ONLYB', artist_names: ['B'], total_amount: '30000.00', unit_count: 1, items_gross: '30000.00', items_net: '30000.00',
    seller_items: { B: part(1, '30000.00') } }),
  tx({ id: 3, order_number: 'ONLYA', artist_names: ['A'], total_amount: '10000.00', unit_count: 1, items_gross: '10000.00', items_net: '10000.00',
    seller_items: { A: part(1, '10000.00') } }),
  tx({ id: 4, order_number: 'VOID', status: 'voided', artist_names: ['A'], total_amount: '99000.00', unit_count: 9, items_gross: '99000.00', items_net: '99000.00',
    seller_items: { A: part(9, '99000.00') } }),
];
const setup = () => useSalesFilters({ transactions: ref(ROWS), sessions: ref([]) });

/** 056 (US9) — statistik filter penjual hanya menghitung ITEM penjual terpilih, bukan seluruh transaksi. */
describe('useSalesFilters — seller-scoped statistics (056 US9)', () => {
  it('one seller: cards and summary count only that seller\'s items', () => {
    const c = setup();
    c.filters.sellers = ['A'];
    expect(c.kpis.value).toMatchObject({ count: 2, units: 2, gross: 60000, net: 60000 });
    expect(c.summary.value).toMatchObject({ units: 2, amount: 60000 });
  });

  it('two sellers: sum of their items, nothing counted twice', () => {
    const c = setup();
    c.filters.sellers = ['A', 'B'];
    expect(c.kpis.value).toMatchObject({ count: 3, units: 5, gross: 160000, net: 160000 });
  });

  it('no seller filter: whole sales', () => {
    const c = setup();
    expect(c.kpis.value).toMatchObject({ count: 3, gross: 160000 });
  });

  it('a seller with no items in a sale is not listed (B-only sale hidden for A)', () => {
    const c = setup();
    c.filters.sellers = ['A'];
    expect(c.filtered.value.map((r) => r.order_number)).not.toContain('ONLYB');
  });

  it('selecting a seller by name (table click) scopes the statistics like the filter does', () => {
    const c = setup();
    c.filterBySeller('A');
    expect(c.filters.sellers).toEqual(['A']);
    expect(c.kpis.value).toMatchObject({ gross: 60000 });
  });
});
