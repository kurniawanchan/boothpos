import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('../../resources/js/api/floor', () => ({ getFloor: vi.fn() }));

import { getFloor } from '../../resources/js/api/floor';
import { useFloorStore } from '../../resources/js/stores/floor';

const floorPayload = (version) => ({
  areas: [{ area: 'Indoor', tables: [{ id: 1, name: '1', seats: 4, is_active: true, state: 'free', tab: null }] }],
  takeaway_tabs: [],
  version,
});

describe('useFloorStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('stores the floor and remembers the version', async () => {
    getFloor.mockResolvedValue(floorPayload('v1'));
    const floor = useFloorStore();
    await floor.fetch();

    expect(floor.areas).toHaveLength(1);
    expect(floor.version).toBe('v1');
    expect(floor.loaded).toBe(true);
  });

  it('sends the last version, and keeps the rendered data when the server says unchanged', async () => {
    getFloor.mockResolvedValueOnce(floorPayload('v1')).mockResolvedValueOnce({ unchanged: true, version: 'v1' });
    const floor = useFloorStore();
    await floor.fetch();
    const before = floor.areas;

    await floor.fetch();

    expect(getFloor).toHaveBeenLastCalledWith('v1');
    expect(floor.areas).toBe(before); // sama persis (tanpa render ulang)
  });

  it('drops the version on a full fetch so the server returns the whole floor', async () => {
    getFloor.mockResolvedValue(floorPayload('v2'));
    const floor = useFloorStore();
    floor.version = 'stale';

    await floor.fetch({ full: true });

    expect(getFloor).toHaveBeenLastCalledWith(null);
    expect(floor.version).toBe('v2');
  });

  it('reports an empty floor only after the first load', async () => {
    const floor = useFloorStore();
    expect(floor.isEmpty).toBe(false);

    getFloor.mockResolvedValue({ areas: [], takeaway_tabs: [], version: 'v0' });
    await floor.fetch();
    expect(floor.isEmpty).toBe(true);
  });
});
