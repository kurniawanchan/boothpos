import { reactive, ref } from 'vue';

/**
 * One reusable composable for the `{ data, meta }` pagination envelope
 * every list endpoint returns (openapi-pos-mvp.yaml `Paginated`) — screens
 * consume `items`, `meta`, `params`, `loading`, `error`, `load()`, and
 * `setPage()` instead of re-implementing this per table.
 */
export function usePaginatedList(fetcher, initialParams = {}) {
  const items = ref([]);
  const meta = ref({ current_page: 1, per_page: 25, total: 0, last_page: 1 });
  const params = reactive({ page: 1, per_page: 25, ...initialParams });
  const loading = ref(false);
  const error = ref(null);

  async function load() {
    loading.value = true;
    error.value = null;
    try {
      const res = await fetcher({ ...params });
      items.value = res.data ?? [];
      if (res.meta) meta.value = res.meta;
    } catch (e) {
      error.value = e;
    } finally {
      loading.value = false;
    }
  }

  function setPage(page) {
    params.page = page;
    return load();
  }

  function setFilter(patch) {
    Object.assign(params, patch, { page: 1 });
    return load();
  }

  return { items, meta, params, loading, error, load, setPage, setFilter };
}
