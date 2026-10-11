import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useQueryState } from '../../resources/js/composables/useQueryState';

async function setup(initial = '/list') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/list', component: { template: '<div />' } }],
  });
  router.push(initial);
  await router.isReady();
  return router;
}

const DEFAULTS = { tab: 'all', q: '', page: 1, sort: 'created_at', voided: false, status: [] };

describe('useQueryState', () => {
  it('memakai bawaan saat URL kosong', async () => {
    const router = await setup();
    const { state } = useQueryState(DEFAULTS, { route: router.currentRoute, router });
    expect(state).toMatchObject(DEFAULTS);
  });

  it('membaca nilai dari URL sesuai tipe bawaan', async () => {
    const router = await setup('/list?tab=open&page=3&voided=1&status=a,b&q=meja');
    const { state } = useQueryState(DEFAULTS, { route: router.currentRoute, router });
    expect(state.tab).toBe('open');
    expect(state.page).toBe(3);
    expect(state.voided).toBe(true);
    expect(state.status).toEqual(['a', 'b']);
    expect(state.q).toBe('meja');
  });

  it('mengabaikan nilai tak valid, bukan fatal', async () => {
    const router = await setup('/list?page=abc&voided=maybe');
    const { state } = useQueryState(DEFAULTS, { route: router.currentRoute, router });
    expect(state.page).toBe(1);
    expect(state.voided).toBe(false);
  });

  it('hanya menulis nilai yang berbeda dari bawaan dan membiarkan kunci lain', async () => {
    const router = await setup('/list?other=keep');
    const { set } = useQueryState(DEFAULTS, { route: router.currentRoute, router });
    await set({ tab: 'open', page: 1, status: ['x'] });
    expect(router.currentRoute.value.query).toEqual({ other: 'keep', tab: 'open', status: 'x' });
  });

  it('menghapus kunci dari URL saat nilai kembali ke bawaan', async () => {
    const router = await setup('/list?tab=open');
    const { set } = useQueryState(DEFAULTS, { route: router.currentRoute, router });
    await set({ tab: 'all' });
    expect(router.currentRoute.value.query.tab).toBeUndefined();
  });

  it('reset mengembalikan semuanya ke bawaan', async () => {
    const router = await setup('/list?tab=open&page=2&q=x');
    const { state, reset } = useQueryState(DEFAULTS, { route: router.currentRoute, router });
    await reset();
    expect(state).toMatchObject(DEFAULTS);
    expect(router.currentRoute.value.query).toEqual({});
  });

  it('mengikuti perubahan URL dari luar (back/forward)', async () => {
    const router = await setup('/list');
    const { state } = useQueryState(DEFAULTS, { route: router.currentRoute, router });
    await router.push('/list?tab=closed');
    await nextTick();
    expect(state.tab).toBe('closed');
  });
});
