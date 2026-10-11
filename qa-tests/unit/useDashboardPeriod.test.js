import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useDashboardPeriod } from '../../resources/js/composables/useDashboardPeriod';

async function setup(initial = '/dashboard') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/dashboard', component: { template: '<div />' } }],
  });
  router.push(initial);
  await router.isReady();
  return router;
}

describe('useDashboardPeriod (042)', () => {
  it('defaults to today with an empty address', async () => {
    const router = await setup();
    const { period } = useDashboardPeriod({ route: router.currentRoute, router });
    expect(period.value).toBe('today');
  });

  it('reads a valid ?period= from the address', async () => {
    for (const p of ['7d', '30d']) {
      const router = await setup(`/dashboard?period=${p}`);
      const { period } = useDashboardPeriod({ route: router.currentRoute, router });
      expect(period.value).toBe(p);
    }
  });

  it('ignores an invalid ?period= instead of calling the API with it', async () => {
    const router = await setup('/dashboard?period=90d');
    const { period } = useDashboardPeriod({ route: router.currentRoute, router });
    expect(period.value).toBe('today');
  });

  it('writes the period to the address, keeps the default out of it and leaves other keys alone', async () => {
    const router = await setup('/dashboard?tab=open');
    const { period, setPeriod } = useDashboardPeriod({ route: router.currentRoute, router });
    await setPeriod('7d');
    expect(period.value).toBe('7d');
    expect(router.currentRoute.value.query).toEqual({ tab: 'open', period: '7d' });
    await setPeriod('today');
    expect(router.currentRoute.value.query).toEqual({ tab: 'open' });
  });

  it('refuses to write an invalid value', async () => {
    const router = await setup('/dashboard?period=7d');
    const { period, setPeriod } = useDashboardPeriod({ route: router.currentRoute, router });
    await setPeriod('yearly');
    expect(period.value).toBe('today');
    expect(router.currentRoute.value.query.period).toBeUndefined();
  });

  it('follows back/forward navigation', async () => {
    const router = await setup();
    const { period } = useDashboardPeriod({ route: router.currentRoute, router });
    await router.push('/dashboard?period=30d');
    expect(period.value).toBe('30d');
  });
});
