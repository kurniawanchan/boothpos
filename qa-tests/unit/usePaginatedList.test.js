import { describe, it, expect, vi } from 'vitest';
import { usePaginatedList } from '../../resources/js/composables/usePaginatedList';

function makeFetcher(pages) {
  return vi.fn((params) => Promise.resolve(pages[params.page] ?? { data: [], meta: { current_page: params.page, per_page: 25, total: 0, last_page: 1 } }));
}

describe('usePaginatedList', () => {
  it('loads the first page on demand and exposes items + meta', async () => {
    const fetcher = makeFetcher({
      1: { data: [{ id: 1 }, { id: 2 }], meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } },
    });
    const { items, meta, loading, load } = usePaginatedList(fetcher);
    expect(loading.value).toBe(false);
    await load();
    expect(items.value).toHaveLength(2);
    expect(meta.value.total).toBe(2);
    expect(fetcher).toHaveBeenCalledWith(expect.objectContaining({ page: 1, per_page: 25 }));
  });

  it('setPage moves to the requested page and refetches', async () => {
    const fetcher = makeFetcher({
      1: { data: [{ id: 1 }], meta: { current_page: 1, per_page: 25, total: 2, last_page: 2 } },
      2: { data: [{ id: 2 }], meta: { current_page: 2, per_page: 25, total: 2, last_page: 2 } },
    });
    const { items, params, setPage } = usePaginatedList(fetcher);
    await setPage(2);
    expect(params.page).toBe(2);
    expect(items.value).toEqual([{ id: 2 }]);
  });

  it('setFilter merges new params and resets to page 1', async () => {
    const fetcher = makeFetcher({ 1: { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } } });
    const { params, setFilter } = usePaginatedList(fetcher, { page: 3 });
    await setFilter({ status: 'active' });
    expect(params.status).toBe('active');
    expect(params.page).toBe(1);
  });

  it('captures a rejected fetch as `error` instead of throwing', async () => {
    const failure = new Error('network down');
    const fetcher = vi.fn().mockRejectedValue(failure);
    const { error, loading, load } = usePaginatedList(fetcher);
    await load();
    expect(error.value).toBe(failure);
    expect(loading.value).toBe(false);
  });
});
