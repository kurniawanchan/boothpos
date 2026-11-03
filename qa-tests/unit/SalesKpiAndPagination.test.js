import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useSalesFilters } from '../../resources/js/composables/useSalesFilters';

// 054 (US5) — kartu ringkasan dan halaman tabel mengikuti SEMUA filter, bukan hanya event.
const tx = (over) => ({
  key: `order:${over.id}`, status: 'completed', created_at: '2026-10-02T03:00:00Z', session_id: 1,
  total_amount: '100000.00', items_gross: '100000.00', items_net: '100000.00', unit_count: 2, item_count: 1,
  cash_amount: '0.00', noncash_amount: '0.00', payment_methods: [], artist_names: [], cashier_id: 1, cashier_name: 'A',
  customer_name: null, payment_status: 'fully_paid', balance_amount: '0.00', ...over,
});

const ROWS = [
  tx({ id: 1, order_number: 'A', cashier_id: 1, cashier_name: 'Kasir A', unit_count: 3, items_gross: '60000.00', items_net: '58000.00', total_amount: '55000.00' }),
  tx({ id: 2, order_number: 'B', cashier_id: 2, cashier_name: 'Kasir B', unit_count: 1, items_gross: '30000.00', items_net: '30000.00', total_amount: '30000.00' }),
  tx({ id: 3, order_number: 'C', cashier_id: 2, cashier_name: 'Kasir B', unit_count: 4, items_gross: '90000.00', items_net: '90000.00', total_amount: '90000.00' }),
  tx({ id: 4, order_number: 'D', status: 'voided', cashier_id: 2, cashier_name: 'Kasir B', unit_count: 9, items_gross: '999.00', items_net: '999.00', total_amount: '999.00' }),
];

const setup = (rows = ROWS) => useSalesFilters({ transactions: ref(rows), sessions: ref([]) });

describe('useSalesFilters — summary cards follow every filter (054 US5)', () => {
  it('derives count, units, gross and net from the filtered rows and never counts voided ones', () => {
    const { kpis } = setup();

    expect(kpis.value).toEqual({ count: 3, units: 8, gross: 180000, net: 178000 });
  });

  it('changes when a filter other than the event is applied', () => {
    const { filters, kpis } = setup();
    filters.cashiers = [2];

    expect(kpis.value).toEqual({ count: 2, units: 5, gross: 120000, net: 120000 });
  });

  it('follows the text search and a total range as well', () => {
    const { search, filters, kpis } = setup();
    search.value = 'kasir a';
    filters.maxTotal = 60000;

    expect(kpis.value.count).toBe(1);
    expect(kpis.value.units).toBe(3);
  });

  it('is all zeros when nothing matches', () => {
    const { search, kpis } = setup();
    search.value = 'zzz-nothing';

    expect(kpis.value).toEqual({ count: 0, units: 0, gross: 0, net: 0 });
  });

  it('falls back to the row total for gross and net when an older row has no item-level fields', () => {
    const legacy = [{ ...tx({ id: 9, order_number: 'OLD', total_amount: '40000.00' }), items_gross: undefined, items_net: undefined }];
    const { kpis } = setup(legacy);

    expect(kpis.value.gross).toBe(40000);
    expect(kpis.value.net).toBe(40000);
  });

  it('counts voided rows in neither the cards nor the units when they are shown for audit', () => {
    const { includeVoided, filtered, kpis } = setup();
    includeVoided.value = true;

    expect(filtered.value).toHaveLength(4);
    expect(kpis.value.count).toBe(3);
  });
});

describe('useSalesFilters — seller filter counts only that seller\'s items', () => {
  const mixed = [
    tx({ id: 1, order_number: 'M1', artist_names: ['vlaisca', 'sapphire'], unit_count: 5, items_gross: '100000.00', items_net: '100000.00', total_amount: '100000.00',
      seller_items: { vlaisca: { units: 2, gross: '40000.00', net: '40000.00' }, sapphire: { units: 3, gross: '60000.00', net: '60000.00' } } }),
    tx({ id: 2, order_number: 'M2', artist_names: ['vlaisca'], unit_count: 1, items_gross: '10000.00', items_net: '10000.00', total_amount: '10000.00',
      seller_items: { vlaisca: { units: 1, gross: '10000.00', net: '10000.00' } } }),
  ];

  it('uses the whole order without a seller filter', () => {
    expect(setup(mixed).kpis.value).toMatchObject({ count: 2, units: 6, gross: 110000, net: 110000 });
  });

  it('counts only the selected seller\'s share of mixed orders (matches the Seller Cost report)', () => {
    const { filters, kpis, summary } = setup(mixed);
    filters.sellers = ['vlaisca'];

    expect(kpis.value).toEqual({ count: 2, units: 3, gross: 50000, net: 50000 });
    expect(summary.value.units).toBe(3);
    expect(summary.value.amount).toBe(50000);
  });
});

const MANY = Array.from({ length: 60 }, (_, i) => tx({ id: i + 1, order_number: `T${String(i + 1).padStart(3, '0')}`, created_at: `2026-10-02T${String(Math.floor(i / 6)).padStart(2, '0')}:${String((i % 6) * 10).padStart(2, '0')}:00Z` }));

describe('useSalesFilters — pagination (054 US5)', () => {
  it('slices the sorted rows into pages of 25 by default', () => {
    const { paged, pageCount, pageMeta } = setup(MANY);

    expect(paged.value).toHaveLength(25);
    expect(pageCount.value).toBe(3);
    expect(pageMeta.value).toMatchObject({ current_page: 1, per_page: 25, total: 60, last_page: 3 });
  });

  it('shows every filtered row exactly once across the pages', () => {
    const { page, paged } = setup(MANY);
    const seen = [];
    for (const p of [1, 2, 3]) {
      page.value = p;
      seen.push(...paged.value.map((r) => r.id));
    }

    expect(seen).toHaveLength(60);
    expect(new Set(seen).size).toBe(60);
  });

  it('keeps the cards and "all filtered rows" independent of the current page', () => {
    const { page, kpis, sorted } = setup(MANY);
    page.value = 3;

    expect(kpis.value.count).toBe(60);
    expect(sorted.value).toHaveLength(60);
  });

  it('returns to page 1 whenever a filter, the search, the sort or the voided toggle changes', () => {
    const { page, filters, search, sortBy, includeVoided } = setup(MANY);

    page.value = 3; filters.cashiers = [1];
    expect(page.value).toBe(1);
    page.value = 3; search.value = 'T0';
    expect(page.value).toBe(1);
    page.value = 3; sortBy({ key: 'total_amount', dir: 'asc' });
    expect(page.value).toBe(1);
    page.value = 3; includeVoided.value = true;
    expect(page.value).toBe(1);
  });

  it('clamps a page that is out of range instead of showing an empty table', () => {
    const { page, currentPage, paged } = setup(MANY);
    page.value = 99;

    expect(currentPage.value).toBe(3);
    expect(paged.value).toHaveLength(10);

    page.value = -4;
    expect(currentPage.value).toBe(1);
  });

  it('supports the page sizes 25, 50 and 100 and ignores any other', () => {
    const { pageSize, paged, applyQuery } = setup(MANY);
    pageSize.value = 50;
    expect(paged.value).toHaveLength(50);

    applyQuery({ size: '7' });
    expect(pageSize.value).toBe(25);
    applyQuery({ size: '100' });
    expect(pageSize.value).toBe(100);
  });

  it('round-trips page and size through the address, leaving defaults out', () => {
    const { page, pageSize, toQuery, applyQuery } = setup(MANY);
    expect(toQuery()).not.toHaveProperty('page');
    expect(toQuery()).not.toHaveProperty('size');

    pageSize.value = 50; page.value = 2; // mengubah ukuran mengembalikan ke halaman 1, jadi halaman diatur sesudahnya
    const q = toQuery();
    expect(q).toMatchObject({ page: '2', size: '50' });

    const other = setup(MANY);
    other.applyQuery(q);
    expect(other.page.value).toBe(2);
    expect(other.pageSize.value).toBe(50);
  });

  it('ignores garbage page values from the address', () => {
    const { page, applyQuery } = setup(MANY);
    applyQuery({ page: 'abc' });
    expect(page.value).toBe(1);
    applyQuery({ page: '-3' });
    expect(page.value).toBe(1);
    applyQuery({ page: '2.5' });
    expect(page.value).toBe(1);
  });

  it('restores the page from the address even though the same call sets filters (which reset the page)', () => {
    const { page, filters, applyQuery } = setup(MANY);
    applyQuery({ cashier: '1', page: '2' });

    expect(filters.cashiers).toEqual(['1']);
    expect(page.value).toBe(2);
  });
});
