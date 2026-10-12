import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

// 045 Stage 2 (T041) — pilihan outlet per pengguna dan pemulihannya terhadap cakupan dari server.
const getScope = vi.fn();
vi.mock('../../resources/js/api/me', () => ({ getScope: (...a) => getScope(...a) }));

import { useOutletStore } from '../../resources/js/stores/outlet';
import { selectedOutletId } from '../../resources/js/utils/outletSelection';

const TWO_OUTLETS = {
  outlets: [
    { id: 1, code: 'WJ1', name: 'Warkop WJM 1' },
    { id: 2, code: 'WJ2', name: 'Warkop WJM 2' },
  ],
  selected: null,
  can_see_all: false,
  multi: true,
};

describe('outlet store (045 Stage 2)', () => {
  beforeEach(() => {
    localStorage.clear();
    setActivePinia(createPinia());
    getScope.mockReset();
    getScope.mockResolvedValue(TWO_OUTLETS);
  });

  it('loads the permitted outlets and the multi flag from the server', async () => {
    const store = useOutletStore();
    await store.load(7);

    expect(store.outlets.map((o) => o.id)).toEqual([1, 2]);
    expect(store.multi).toBe(true);
    expect(store.canSeeAll).toBe(false);
    expect(store.loaded).toBe(true);
  });

  it('keeps a selection per user: another account on the same device starts with none', async () => {
    const store = useOutletStore();
    await store.load(7);
    store.select(2);

    const other = useOutletStore();
    other.reset();
    await other.load(8);

    expect(other.selected).toBeNull();
    expect(selectedOutletId()).toBeNull();
  });

  it('restores the selection of the same user on the next load', async () => {
    const first = useOutletStore();
    await first.load(7);
    first.select(2);
    first.reset();

    const again = useOutletStore();
    await again.load(7);

    expect(again.selected).toBe(2);
    expect(selectedOutletId()).toBe(2);
  });

  it('drops a stored selection that is no longer permitted instead of sending a header the server would refuse', async () => {
    const first = useOutletStore();
    await first.load(7);
    first.select(2);
    first.reset();

    getScope.mockResolvedValueOnce({ ...TWO_OUTLETS, outlets: [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }], multi: false });
    const again = useOutletStore();
    await again.load(7);

    expect(again.selected).toBeNull();
    expect(selectedOutletId()).toBeNull();
    expect(localStorage.getItem('boothpos.outlet.7')).toBeNull();
  });

  it('select(null) returns to all permitted outlets', async () => {
    const store = useOutletStore();
    await store.load(7);
    store.select(1);
    store.select(null);

    expect(store.selected).toBeNull();
    expect(localStorage.getItem('boothpos.outlet.7')).toBeNull();
  });
});
