import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref } from 'vue';
import { usePreorderSelection } from '../../resources/js/composables/usePreorderSelection';
import { matchingPreorderIds } from '../../resources/js/api/preorders';

vi.mock('../../resources/js/api/preorders', () => ({ matchingPreorderIds: vi.fn() }));

/** 065 US1 — seleksi: halaman, sebagian, semua yang cocok (terpotong), kosongkan; hitungan = ukuran Set. */
function make({ rows = [1, 2, 3], total = 3, max = 200 } = {}) {
  const items = ref(rows.map((id) => ({ id })));
  const meta = ref({ total });
  const params = { status: ['dp_paid'], page: 1 };
  return { items, meta, params, sel: usePreorderSelection({ items, meta, params, max: ref(max) }) };
}

describe('usePreorderSelection (065)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('toggles single rows and the count always equals the selected set', () => {
    const { sel } = make();
    sel.toggle(1);
    sel.toggle(3);
    expect(sel.count.value).toBe(2);
    sel.toggle(1);
    expect(sel.count.value).toBe(1);
    expect([...sel.selectedIds.value]).toEqual([3]);
  });

  it('shows the partial state for some rows and selects/deselects the whole page', () => {
    const { sel } = make();
    sel.toggle(1);
    expect(sel.someOnPage.value).toBe(true);
    expect(sel.allOnPage.value).toBe(false);

    sel.togglePage(); // melengkapi halaman
    expect(sel.allOnPage.value).toBe(true);
    expect(sel.someOnPage.value).toBe(false);
    expect(sel.count.value).toBe(3);

    sel.togglePage(); // semua dicabut
    expect(sel.count.value).toBe(0);
  });

  it('offers "select all matching" only when the full page is selected and more match elsewhere', () => {
    const { sel } = make({ rows: [1, 2, 3], total: 10 });
    expect(sel.canOfferAllMatching.value).toBe(false);
    sel.togglePage();
    expect(sel.canOfferAllMatching.value).toBe(true);

    const single = make({ rows: [1, 2, 3], total: 3 });
    single.sel.togglePage();
    expect(single.sel.canOfferAllMatching.value).toBe(false);
  });

  it('selects all matching ids across pages with the current filters and reports a capped result', async () => {
    matchingPreorderIds.mockResolvedValue({ ids: [1, 2, 3, 4, 5], total: 340, capped: true });
    const { sel, params } = make({ rows: [1, 2, 3], total: 340, max: 5 });
    sel.togglePage();

    await sel.selectAllMatching();

    expect(matchingPreorderIds).toHaveBeenCalledWith({ ...params });
    expect(sel.count.value).toBe(5);
    expect(sel.matching.value).toEqual({ total: 340, capped: true });
    expect(sel.canOfferAllMatching.value).toBe(false);
  });

  it('flags a selection above the limit and clears everything', () => {
    const { sel } = make({ rows: [1, 2, 3], total: 3, max: 2 });
    sel.togglePage();
    expect(sel.overLimit.value).toBe(true);
    sel.clear();
    expect(sel.count.value).toBe(0);
    expect(sel.matching.value).toBeNull();
    expect(sel.overLimit.value).toBe(false);
  });

  it('replace() sets exactly the given ids (used by "retry these")', () => {
    const { sel } = make();
    sel.togglePage();
    sel.replace([2]);
    expect([...sel.selectedIds.value]).toEqual([2]);
  });
});
