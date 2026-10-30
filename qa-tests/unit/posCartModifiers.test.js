import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { usePosCartStore, cartLineKey } from '../../resources/js/stores/posCart';
import { describeSelection } from '../../resources/js/utils/modifierRules';

// 084 — baris keranjang POS web dikunci varian + pilihan modifier.
const line = (overrides = {}) => ({
  variant_id: 1,
  sku: 'WJMKOPI00001',
  name: 'Kopi Susu',
  sell_price: '20000.00',
  current_stock: 10,
  ...overrides,
});

describe('posCart modifiers', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('keeps the plain variant id as the key when there are no modifiers', () => {
    expect(cartLineKey(line())).toBe('1');
    expect(cartLineKey(line({ modifier_option_ids: [] }))).toBe('1');
  });

  it('sorts option ids so selection order does not change the key', () => {
    expect(cartLineKey(line({ modifier_option_ids: [9, 5] }))).toBe(cartLineKey(line({ modifier_option_ids: [5, 9] })));
    expect(cartLineKey(line({ modifier_option_ids: [9, 5] }))).toBe('1:5,9');
  });

  it('merges the same variant with the same options into one line', () => {
    const cart = usePosCartStore();
    cart.add(line({ modifier_option_ids: [5], sell_price: '23000.00' }));
    cart.add(line({ modifier_option_ids: [5], sell_price: '23000.00' }));
    expect(cart.items).toHaveLength(1);
    expect(cart.items[0].qty).toBe(2);
  });

  it('keeps different option choices of one variant as separate lines', () => {
    const cart = usePosCartStore();
    cart.add(line());
    cart.add(line({ modifier_option_ids: [5], sell_price: '23000.00' }));
    expect(cart.items).toHaveLength(2);
    expect(cart.subtotal).toBe('43000.00');
  });

  it('increments, decrements and removes by line key without touching the sibling line', () => {
    const cart = usePosCartStore();
    cart.add(line());
    cart.add(line({ modifier_option_ids: [5], sell_price: '23000.00' }));
    const withMod = cartLineKey(cart.items[1]);

    cart.increment(withMod);
    expect(cart.items[1].qty).toBe(2);
    expect(cart.items[0].qty).toBe(1);

    cart.decrement(withMod);
    cart.decrement(withMod);
    expect(cart.items).toHaveLength(1);
    expect(cart.items[0].modifier_option_ids).toBeUndefined();
  });

  it('flags a cart that holds a modifier line', () => {
    const cart = usePosCartStore();
    cart.add(line());
    expect(cart.hasModifiers).toBe(false);
    cart.add(line({ modifier_option_ids: [5] }));
    expect(cart.hasModifiers).toBe(true);
  });
});

describe('describeSelection', () => {
  const groups = [
    { id: 1, name: 'Level gula', options: [{ id: 5, name: 'Kurang manis' }, { id: 6, name: 'Normal' }] },
    { id: 2, name: 'Topping', options: [{ id: 9, name: 'Boba' }] },
  ];

  it('returns group/option pairs for the picked ids only, in group order', () => {
    expect(describeSelection(groups, [9, 5])).toEqual([
      { group: 'Level gula', option: 'Kurang manis' },
      { group: 'Topping', option: 'Boba' },
    ]);
  });

  it('returns nothing for an empty selection', () => {
    expect(describeSelection(groups, [])).toEqual([]);
  });
});
