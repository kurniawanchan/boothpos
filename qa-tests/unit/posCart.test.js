import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { usePosCartStore } from '../../resources/js/stores/posCart';

const variant = (overrides = {}) => ({
  variant_id: 1,
  sku: 'RYUKYSAK0007',
  name: 'Keychain Sakura',
  artist_name: 'Ryuuka Studio',
  sell_price: '45000.00',
  current_stock: 10,
  ...overrides,
});

describe('usePosCartStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('starts empty', () => {
    const cart = usePosCartStore();
    expect(cart.isEmpty).toBe(true);
    expect(cart.count).toBe(0);
    expect(cart.subtotal).toBe('0.00');
  });

  it('adds a new variant with qty 1', () => {
    const cart = usePosCartStore();
    cart.add(variant());
    expect(cart.items).toHaveLength(1);
    expect(cart.items[0].qty).toBe(1);
    expect(cart.count).toBe(1);
  });

  it('increments qty instead of duplicating when adding the same variant twice', () => {
    const cart = usePosCartStore();
    cart.add(variant());
    cart.add(variant());
    expect(cart.items).toHaveLength(1);
    expect(cart.items[0].qty).toBe(2);
  });

  it('computes subtotal as the sum of line totals', () => {
    const cart = usePosCartStore();
    cart.add(variant({ variant_id: 1, sell_price: '45000.00' }));
    cart.add(variant({ variant_id: 2, sell_price: '25000.00' }));
    cart.increment(2); // qty 2 for variant 2
    expect(cart.subtotal).toBe('95000.00'); // 45000 + 25000*2
  });

  it('removes an item once its qty is decremented to zero', () => {
    const cart = usePosCartStore();
    cart.add(variant());
    cart.decrement(1);
    expect(cart.isEmpty).toBe(true);
  });

  it('clears the whole cart', () => {
    const cart = usePosCartStore();
    cart.add(variant({ variant_id: 1 }));
    cart.add(variant({ variant_id: 2 }));
    cart.clear();
    expect(cart.items).toHaveLength(0);
  });
});
