import { describe, it, expect } from 'vitest';
import { buildProductCards, toCartItem } from '../../resources/js/utils/posProductCards';

const variant = (overrides = {}) => ({
  id: 1,
  sku: 'RYUKYSAK0001',
  variant_name: 'Standard',
  sell_price: '25000.00',
  current_stock: 10,
  is_active: true,
  ...overrides,
});

const product = (overrides = {}) => ({
  id: 1,
  name: 'Keychain Sakura',
  artist_name: 'Ryu Illustration',
  category_id: 2,
  variants: [variant()],
  ...overrides,
});

describe('buildProductCards', () => {
  it('produces one card per product regardless of variant count', () => {
    const products = [
      product({ id: 1, variants: [variant({ id: 1 }), variant({ id: 2 })] }),
      product({ id: 2, name: 'Poster A2', variants: [variant({ id: 3 })] }),
    ];
    const cards = buildProductCards(products);
    expect(cards).toHaveLength(2);
    expect(cards[0].product_id).toBe(1);
    expect(cards[0].variant_count).toBe(2);
    expect(cards[1].variant_count).toBe(1);
  });

  it('filters out inactive variants from the sellable set and aggregates', () => {
    const products = [
      product({
        variants: [
          variant({ id: 1, sell_price: '20000.00', current_stock: 5 }),
          variant({ id: 2, sell_price: '30000.00', current_stock: 7, is_active: false }),
        ],
      }),
    ];
    const [card] = buildProductCards(products);
    expect(card.variant_count).toBe(1);
    expect(card.variants.map((v) => v.id)).toEqual([1]);
    expect(card.total_stock).toBe(5);
    expect(card.min_price).toBe(20000);
    expect(card.max_price).toBe(20000);
  });

  it('computes a min/max price range across multiple active variants', () => {
    const products = [
      product({
        variants: [
          variant({ id: 1, sell_price: '20000.00', current_stock: 3 }),
          variant({ id: 2, sell_price: '35000.00', current_stock: 4 }),
        ],
      }),
    ];
    const [card] = buildProductCards(products);
    expect(card.min_price).toBe(20000);
    expect(card.max_price).toBe(35000);
    expect(card.total_stock).toBe(7);
    expect(card.out_of_stock).toBe(false);
  });

  it('marks a card out of stock when every active variant is at zero stock', () => {
    const products = [product({ variants: [variant({ current_stock: 0 })] })];
    const [card] = buildProductCards(products);
    expect(card.out_of_stock).toBe(true);
  });

  it('marks a card out of stock when it has no active variants at all', () => {
    const products = [product({ variants: [variant({ is_active: false })] })];
    const [card] = buildProductCards(products);
    expect(card.variant_count).toBe(0);
    expect(card.out_of_stock).toBe(true);
  });

  it('resolves category_code from the lookup map, defaulting to null', () => {
    const cards = buildProductCards([product({ category_id: 2 })], { 2: 'KY' });
    expect(cards[0].category_code).toBe('KY');
    expect(buildProductCards([product({ category_id: 99 })])[0].category_code).toBeNull();
  });
});

describe('toCartItem', () => {
  it('keeps the bare product name for a single-variant shortcut add', () => {
    const card = { name: 'Poster A2', artist_name: 'Ryu', variant_count: 1 };
    const item = toCartItem(card, variant({ id: 5, variant_name: 'Standard' }));
    expect(item.name).toBe('Poster A2');
    expect(item.variant_id).toBe(5);
  });

  it('disambiguates the name with the variant when the product has more than one', () => {
    const card = { name: 'Keychain Sakura', artist_name: 'Ryu', variant_count: 2 };
    const item = toCartItem(card, variant({ id: 7, variant_name: 'Large' }));
    expect(item.name).toBe('Keychain Sakura — Large');
  });

  it('carries sku, price, and stock straight from the chosen variant', () => {
    const card = { name: 'Poster A2', artist_name: 'Ryu', variant_count: 1 };
    const item = toCartItem(card, variant({ sku: 'RYUKYSAK0009', sell_price: '99000.00', current_stock: 3 }));
    expect(item.sku).toBe('RYUKYSAK0009');
    expect(item.sell_price).toBe('99000.00');
    expect(item.current_stock).toBe(3);
  });
});
