import { describe, it, expect } from 'vitest';
import { moveRow, excludedIds } from '../../resources/js/utils/modifierExclusion';

// 086 — pemindahan baris grup warisan antara "Dari kategori" dan "Dilepas untuk item ini".
const A = { group_id: 9, name: 'Suhu', display_order: 2 };
const B = { group_id: 4, name: 'Es', display_order: 1 };

describe('moveRow', () => {
  it('moves a row from one list to the other without touching the inputs', () => {
    const from = [A, B];
    const to = [];
    const out = moveRow(from, to, 9);

    expect(out.from).toEqual([B]);
    expect(out.to).toEqual([A]);
    expect(from).toEqual([A, B]);
    expect(to).toEqual([]);
  });

  it('keeps the destination in display order, then by group id', () => {
    expect(moveRow([A], [B], 9).to.map((r) => r.group_id)).toEqual([4, 9]);
  });

  it('does nothing for an id that is not in the source list', () => {
    const out = moveRow([A], [B], 123);
    expect(out.from).toEqual([A]);
    expect(out.to).toEqual([B]);
  });

  it('round-trips: exclude then restore gives back the same rows', () => {
    const first = moveRow([A, B], [], 9);
    const back = moveRow(first.to, first.from, 9);
    expect(back.to.map((r) => r.group_id)).toEqual([4, 9]);
    expect(back.from).toEqual([]);
  });
});

describe('excludedIds', () => {
  it('lists the ids ascending so two equal sets compare equal as JSON', () => {
    expect(excludedIds([A, B])).toEqual([4, 9]);
    expect(JSON.stringify(excludedIds([B, A]))).toBe(JSON.stringify(excludedIds([A, B])));
    expect(excludedIds([])).toEqual([]);
  });
});
