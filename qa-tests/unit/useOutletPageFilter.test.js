import { describe, it, expect, beforeEach } from 'vitest';
import { nextTick } from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { useOutletPageFilter } from '../../resources/js/composables/useOutletPageFilter';

// 071 — filter Outlet halaman (Restaurant Setup): terisi dari bilah atas, boleh dilepas ke "Semua outlet", hanya outlet
// yang boleh dilihat yang berlaku, dan hanya tampil bila ada lebih dari satu outlet.
const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];

function setup({ outlets = OUTLETS, selected = null, multi = outlets.length > 1 } = {}) {
  setActivePinia(createPinia());
  Object.assign(useOutletStore(), { outlets, selected, multi });
  return { store: useOutletStore(), filter: useOutletPageFilter() };
}

describe('useOutletPageFilter (071)', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('starts with every outlet when the top bar has none, and from the top-bar outlet otherwise', () => {
    expect(setup().filter.ids.value).toEqual([]);
    expect(setup({ selected: 2 }).filter.ids.value).toEqual([2]);
  });

  it('is visible only with several outlets, and then lists them as options', () => {
    const { filter } = setup();
    expect(filter.visible.value).toBe(true);
    expect(filter.options.value).toEqual([{ value: 1, label: 'Warkop WJM 1' }, { value: 2, label: 'Warkop WJM 2' }]);
    const single = setup({ outlets: [OUTLETS[0]] });
    expect(single.filter.visible.value).toBe(false);
    expect(single.filter.ids.value).toEqual([]);
  });

  it('keeps an explicit "all outlets" while the top bar has an outlet, and follows the top bar when it changes', async () => {
    const { store, filter } = setup({ selected: 2 });
    filter.set([]);
    expect(filter.ids.value).toEqual([]);
    expect(filter.active.value).toBe(true);

    store.selected = 1;
    await nextTick();

    expect(filter.ids.value).toEqual([1]);
    expect(filter.active.value).toBe(false);
  });

  it('only counts outlets the user may see', () => {
    const { filter } = setup();
    filter.set([2, 99]);
    expect(filter.ids.value).toEqual([2]);
  });

  it('resets to its starting point (top-bar outlet or all)', () => {
    const { filter } = setup({ selected: 1 });
    filter.set([1, 2]);
    expect(filter.active.value).toBe(true);
    filter.reset();
    expect(filter.ids.value).toEqual([1]);
    expect(filter.active.value).toBe(false);
  });
});
