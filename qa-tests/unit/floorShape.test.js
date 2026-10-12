import { describe, it, expect } from 'vitest';
import { normalizeFloor } from '../../resources/js/utils/floorShape';

// 045 Stage 2 (D17) — bentuk lantai satu outlet vs beberapa outlet.
describe('normalizeFloor', () => {
  it('leaves the single-outlet shape untouched', () => {
    const data = { areas: [{ area: 'Indoor', tables: [{ id: 1 }] }], takeaway_tabs: [{ id: 9 }], version: 'v' };
    const out = normalizeFloor(data);
    expect(out.areas).toBe(data.areas);
    expect(out.takeaway_tabs).toBe(data.takeaway_tabs);
    expect(out.outlets).toBeNull();
  });

  it('flattens several outlets, prefixing areas with the outlet name and tagging tabs', () => {
    const out = normalizeFloor({
      outlets: [
        { id: 1, code: 'WJ1', name: 'Warkop 1', areas: [{ area: 'Indoor', tables: [] }, { area: '', tables: [] }], takeaway_tabs: [{ id: 5 }] },
        { id: 2, code: 'WJ2', name: 'Warkop 2', areas: [{ area: 'Indoor', tables: [] }], takeaway_tabs: [] },
      ],
    });

    expect(out.areas.map((a) => a.area)).toEqual(['Warkop 1 · Indoor', 'Warkop 1', 'Warkop 2 · Indoor']);
    expect(out.areas.map((a) => a.outlet_id)).toEqual([1, 1, 2]);
    expect(out.takeaway_tabs).toEqual([{ id: 5, outlet_id: 1 }]);
    expect(out.outlets.map((o) => o.code)).toEqual(['WJ1', 'WJ2']);
  });

  it('tolerates an empty payload', () => {
    expect(normalizeFloor(null)).toEqual({ areas: [], takeaway_tabs: [], outlets: null });
  });
});
