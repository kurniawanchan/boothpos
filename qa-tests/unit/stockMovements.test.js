import { describe, it, expect } from 'vitest';
import { MOVEMENT_TYPES, movementTypeLabelKey, movementTypeVariant } from '../../resources/js/utils/stockMovements';
import en from '../../resources/js/locales/en.json';
import id from '../../resources/js/locales/id.json';

describe('stockMovements util (036)', () => {
  it('has a label in both languages for every movement type', () => {
    for (const type of MOVEMENT_TYPES) {
      const key = movementTypeLabelKey(type).split('.')[1];
      expect(en.master_data[key], `en ${type}`).toBeTruthy();
      expect(id.master_data[key], `id ${type}`).toBeTruthy();
    }
  });

  it('maps types to pill variants and falls back to neutral for unknown types', () => {
    expect(movementTypeVariant('purchase')).toBe('mint');
    expect(movementTypeVariant('preorder_handover')).toBe('warn');
    expect(movementTypeVariant('something_new')).toBe('neutral');
  });
});
