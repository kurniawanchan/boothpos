import { describe, it, expect } from 'vitest';
import { groupRange, describeRange, isSelectionValid, selectionDelta, selectedOptionIds } from '../../resources/js/utils/modifierRules';

// Harus sejalan dengan ModifierResolver::range() di server (tests/Feature/ModifierResolverTest).
const single = (over) => ({ id: 1, selection_type: 'single', is_required: true, min_select: 1, max_select: 1, options: [], ...over });
const multi = (over) => ({ id: 2, selection_type: 'multiple', is_required: false, min_select: 0, max_select: 2, options: [], ...over });

describe('groupRange', () => {
  it('single choice: exactly one when required, zero-or-one when optional', () => {
    expect(groupRange(single())).toEqual([1, 1]);
    expect(groupRange(single({ is_required: false }))).toEqual([0, 1]);
  });

  it('single choice ignores whatever min/max the payload carries', () => {
    expect(groupRange(single({ min_select: 5, max_select: 9 }))).toEqual([1, 1]);
  });

  it('multiple choice: a required group needs at least one, even when min is 0', () => {
    expect(groupRange(multi({ is_required: true, min_select: 0 }))).toEqual([1, 2]);
    expect(groupRange(multi({ is_required: false, min_select: 0 }))).toEqual([0, 2]);
  });

  it('multiple choice with no upper bound', () => {
    expect(groupRange(multi({ max_select: null }))).toEqual([0, null]);
  });
});

describe('describeRange', () => {
  it('matches the wording the server uses in its 422 message', () => {
    expect(describeRange([1, 1])).toBe('1');
    expect(describeRange([1, 2])).toBe('1–2');
    expect(describeRange([0, null])).toBe('≥0');
    expect(describeRange([2, null])).toBe('≥2');
  });
});

describe('isSelectionValid', () => {
  const groups = [single({ id: 1 }), multi({ id: 2, max_select: 2 })];

  it('is invalid until every required group has its choice', () => {
    expect(isSelectionValid(groups, { 1: [], 2: [] })).toBe(false);
    expect(isSelectionValid(groups, { 1: [10], 2: [] })).toBe(true);
  });

  it('rejects too many choices in a bounded group', () => {
    expect(isSelectionValid(groups, { 1: [10], 2: [20, 21, 22] })).toBe(false);
    expect(isSelectionValid(groups, { 1: [10], 2: [20, 21] })).toBe(true);
  });

  it('treats a group with no entry as an empty selection', () => {
    expect(isSelectionValid([multi()], {})).toBe(true);
    expect(isSelectionValid([single()], {})).toBe(false);
  });
});

describe('selectionDelta and selectedOptionIds', () => {
  const groups = [
    single({ id: 1, options: [{ id: 10, price_delta: '0.00' }, { id: 11, price_delta: '5000.00' }] }),
    multi({ id: 2, options: [{ id: 20, price_delta: '8000.00' }, { id: 21, price_delta: '-2000.00' }] }),
  ];

  it('sums signed differences of the chosen options only', () => {
    expect(selectionDelta(groups, { 1: [11], 2: [20, 21] })).toBe(11000);
    expect(selectionDelta(groups, { 1: [10], 2: [] })).toBe(0);
  });

  it('flattens the chosen ids across groups', () => {
    expect(selectedOptionIds({ 1: [11], 2: [20, 21] })).toEqual([11, 20, 21]);
  });
});
