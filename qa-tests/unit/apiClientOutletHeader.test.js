import { describe, it, expect, vi, beforeEach } from 'vitest';

// 070 (T003) — header X-Outlet-Id hanya menyempitkan di server. Halaman Produk punya filter Outlet sendiri dan
// meminta `skipOutletHeader`; panggilan lain tetap membawa header seperti sebelumnya.
const { selected } = vi.hoisted(() => ({ selected: { id: null } }));
vi.mock('../../resources/js/utils/outletSelection', () => ({ selectedOutletId: () => selected.id }));

import client from '../../resources/js/api/client';

const runRequestInterceptor = (config) => client.interceptors.request.handlers[0].fulfilled({ headers: {}, ...config });

describe('api client X-Outlet-Id header', () => {
  beforeEach(() => {
    selected.id = null;
  });

  it('sends the header when an outlet is chosen in the top bar', () => {
    selected.id = 2;
    expect(runRequestInterceptor({}).headers['X-Outlet-Id']).toBe('2');
  });

  it('omits the header with skipOutletHeader even when an outlet is chosen', () => {
    selected.id = 2;
    expect(runRequestInterceptor({ skipOutletHeader: true }).headers['X-Outlet-Id']).toBeUndefined();
  });

  it('sends no header when nothing is chosen', () => {
    expect(runRequestInterceptor({}).headers['X-Outlet-Id']).toBeUndefined();
  });
});
