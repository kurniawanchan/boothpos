import { describe, it, expect } from 'vitest';
import { buildProductCards, buildSearchCards, toCartItem, stockOf, isOutOfStock } from '../../resources/js/utils/posProductCards';

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
  home_outlet_name: 'Ryu Illustration',
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

  it('resolves category_name from the lookup map, defaulting to null', () => {
    const cards = buildProductCards([product({ category_id: 2 })], { 2: 'KY' }, { 2: 'Keychain' });
    expect(cards[0].category_name).toBe('Keychain');
    expect(buildProductCards([product({ category_id: 99 })])[0].category_name).toBeNull();
  });
});

describe('toCartItem', () => {
  it('keeps the bare product name for a single-variant shortcut add', () => {
    const card = { name: 'Poster A2', home_outlet_name: 'Ryu', variant_count: 1 };
    const item = toCartItem(card, variant({ id: 5, variant_name: 'Standard' }));
    expect(item.name).toBe('Poster A2');
    expect(item.variant_id).toBe(5);
  });

  it('disambiguates the name with the variant when the product has more than one', () => {
    const card = { name: 'Keychain Sakura', home_outlet_name: 'Ryu', variant_count: 2 };
    const item = toCartItem(card, variant({ id: 7, variant_name: 'Large' }));
    expect(item.name).toBe('Keychain Sakura — Large');
  });

  it('carries sku, price, and stock straight from the chosen variant', () => {
    const card = { name: 'Poster A2', home_outlet_name: 'Ryu', variant_count: 1 };
    const item = toCartItem(card, variant({ sku: 'RYUKYSAK0009', sell_price: '99000.00', current_stock: 3 }));
    expect(item.sku).toBe('RYUKYSAK0009');
    expect(item.sell_price).toBe('99000.00');
    expect(item.current_stock).toBe(3);
  });

  it("uses the variant's own image when it has one", () => {
    const card = { name: 'Poster A2', home_outlet_name: 'Ryu', variant_count: 1, image_url: 'https://example.test/product.png' };
    const item = toCartItem(card, variant({ image_url: 'https://example.test/variant.png' }));
    expect(item.image_url).toBe('https://example.test/variant.png');
  });

  it("falls back to the product's image when the variant has none", () => {
    const card = { name: 'Poster A2', home_outlet_name: 'Ryu', variant_count: 1, image_url: 'https://example.test/product.png' };
    const item = toCartItem(card, variant({ image_url: null }));
    expect(item.image_url).toBe('https://example.test/product.png');
  });
});

// 030-fix-pos-search-product-image — kartu hasil pencarian dulu dibuat inline di PosView
// dengan memilih sebagian field saja, sehingga `image_url` (yang sudah dikirim
// GET /variants/lookup) hilang dan kartu menampilkan ikon placeholder.
describe('buildSearchCards', () => {
  const hit = (overrides = {}) => ({
    variant_id: 7,
    sku: 'SPF-PI-MCY-014',
    label: 'MCYT — Slippery',
    home_outlet_name: 'sapphirefiless',
    category_name: 'Pin',
    sell_price: '15000.00',
    current_stock: 20,
    is_preorder: false,
    image_url: 'http://localhost/storage/variants/slippery.png',
    ...overrides,
  });

  it('carries the hit\'s image_url onto the card (the photo the lookup already resolved)', () => {
    const [card] = buildSearchCards([hit()]);

    expect(card.image_url).toBe('http://localhost/storage/variants/slippery.png');
  });

  it('gives image_url null when the hit has no photo (null or missing), so the placeholder shows', () => {
    const [withNull, missing] = buildSearchCards([hit({ image_url: null }), hit({ variant_id: 8, image_url: undefined })]);

    expect(withNull.image_url).toBeNull();
    expect(missing.image_url).toBeNull();
  });

  it('carries the hit\'s category_name onto the card, null when the hit has none', () => {
    const [withCategory, without, missing] = buildSearchCards([hit(), hit({ variant_id: 8, category_name: null }), hit({ variant_id: 9, category_name: undefined })]);

    expect(withCategory.category_name).toBe('Pin');
    expect(without.category_name).toBeNull();
    expect(missing.category_name).toBeNull();
  });

  it('maps every other field exactly as before and keeps the order of the hits', () => {
    const cards = buildSearchCards([hit({ variant_id: 1 }), hit({ variant_id: 2, label: 'MCYT — Slippery (40cm)', sell_price: '40000.00', current_stock: 9 })]);

    expect(cards.map((c) => c.variant_id)).toEqual([1, 2]);
    expect(cards[1]).toEqual({
      variant_id: 2,
      sku: 'SPF-PI-MCY-014',
      name: 'MCYT — Slippery (40cm)',
      home_outlet_name: 'sapphirefiless',
      sell_price: '40000.00',
      current_stock: 9,
      category_code: null,
      category_name: 'Pin',
      image_url: 'http://localhost/storage/variants/slippery.png',
      modifier_groups: [], // 084 — hasil tanpa grup modifier tetap membawa daftar kosong
    });
  });

  it('carries the effective modifier groups of a hit through to the card (084)', () => {
    const groups = [{ id: 1, name: 'Level gula', selection_type: 'single', is_required: true, options: [{ id: 5, name: 'Normal', price_delta: '0.00' }] }];
    expect(buildSearchCards([hit({ variant_id: 3, modifier_groups: groups })])[0].modifier_groups).toEqual(groups);
  });

  it('returns an empty list for null/undefined/empty input', () => {
    expect(buildSearchCards(null)).toEqual([]);
    expect(buildSearchCards(undefined)).toEqual([]);
    expect(buildSearchCards([])).toEqual([]);
  });

});

// 045 Stage 3 — kasir menampilkan harga khusus outlet bila ada.
import { shownPrice } from '../../resources/js/utils/posProductCards';

describe('shownPrice (045 Stage 3)', () => {
  it('prefers the outlet price and falls back to the base price', () => {
    expect(shownPrice({ sell_price: '20000.00', outlet_price: '25000.00' })).toBe('25000.00');
    expect(shownPrice({ sell_price: '20000.00', outlet_price: null })).toBe('20000.00');
    expect(shownPrice({ sell_price: '20000.00' })).toBe('20000.00');
  });
});

// 045 Stage 5 — kartu POS memakai saldo outlet shift (`outlet_stock`) bila ada, selain itu total.
describe('posProductCards on-hand (045 Stage 5)', () => {
  it('uses outlet_stock for the card and cart item, falling back to current_stock', async () => {
    const { buildProductCards } = await import('../../resources/js/utils/posProductCards');
    const cards = buildProductCards([{ id: 1, name: 'A', category_id: 1, variants: [{ id: 11, sku: 'S', variant_name: 'R', sell_price: '1000.00', current_stock: 9, outlet_stock: 2, is_active: true }] }]);
    expect(cards[0].total_stock).toBe(2);
    expect(toCartItem(cards[0], cards[0].variants[0]).current_stock).toBe(2);
    const plain = buildProductCards([{ id: 1, name: 'A', category_id: 1, variants: [{ id: 11, sku: 'S', variant_name: 'R', sell_price: '1000.00', current_stock: 9, is_active: true }] }]);
    expect(plain[0].total_stock).toBe(9);
  });
});

// BUG YANG DITEMUKAN & DIPERBAIKI (087, laporan pemilik 2026-10-31): POS ritel mengabaikan `track_stock`, jadi item
// bersumber stok modifier (dan menu masak sejak 028) tampil "Stok 0 / Habis" dan tidak bisa dijual.
describe('items without their own stock (track_stock: false)', () => {
  it('a card whose variants do not track stock is never out of stock and shows no stock number', () => {
    const [card] = buildProductCards([product({ variants: [variant({ current_stock: 0, outlet_stock: 0, track_stock: false })] })]);
    expect(card.out_of_stock).toBe(false);
    expect(card.total_stock).toBeNull();
  });

  it('a tracked card still goes out of stock at zero', () => {
    const [card] = buildProductCards([product({ variants: [variant({ current_stock: 0, outlet_stock: 0, track_stock: true })] })]);
    expect(card.out_of_stock).toBe(true);
    expect(card.total_stock).toBe(0);
  });

  it('cart items and search cards carry null stock for untracked variants and the outlet balance otherwise', () => {
    const [card] = buildProductCards([product({ variants: [variant({ current_stock: 0, outlet_stock: 0, track_stock: false })] })]);
    expect(toCartItem(card, card.variants[0]).current_stock).toBeNull();
    const [tracked] = buildProductCards([product({ variants: [variant({ current_stock: 9, outlet_stock: 4 })] })]);
    expect(toCartItem(tracked, tracked.variants[0]).current_stock).toBe(4);

    const [search] = buildSearchCards([{ variant_id: 5, label: 'Indomie Kuah', sell_price: '15000.00', current_stock: 0, outlet_stock: 0, track_stock: false }]);
    expect(search.current_stock).toBeNull();
  });

  it('stockOf and isOutOfStock are the one rule every POS control uses', () => {
    expect(stockOf({ track_stock: false, current_stock: 0, outlet_stock: 0 })).toBeNull();
    expect(stockOf({ current_stock: 7 })).toBe(7);
    expect(isOutOfStock(null)).toBe(false);
    expect(isOutOfStock(0)).toBe(true);
    expect(isOutOfStock(3)).toBe(false);
  });
});

// 2026-10-31 (permintaan pemilik) — produk bersumber bahan modifier menampilkan stok dari bahan yang dipetakan.
describe('material-based stock on POS cards', () => {
  const groups = [{
    id: 1, name: 'Rasa', selection_type: 'multiple', is_required: true, min_select: 1, max_select: 2,
    options: [
      { id: 11, name: 'SOTO', portions: 17, materials: [{ material_id: 9 }] },
      { id: 12, name: 'AYAM BAWANG', portions: 0, materials: [{ material_id: 10 }] },
    ],
  }];
  const product = (over = {}) => ({
    id: 789, name: 'Indomie Kuah', stock_source: 'modifier', modifier_groups: groups,
    variants: [{ id: 1, sku: 'X', variant_name: 'Standard', sell_price: '8000.00', is_active: true, track_stock: false, current_stock: 0 }],
    ...over,
  });

  it('shows the portions the mapped materials still allow, without blocking the sale', () => {
    const [card] = buildProductCards([product()]);
    expect(card.total_stock).toBeNull();
    expect(card.material_portions).toBe(17);
    expect(card.out_of_stock).toBe(false);
  });

  it('keeps zero visible but still sellable (sales never fail on materials, FR-014)', () => {
    const empty = groups.map((g) => ({ ...g, options: g.options.map((o) => ({ ...o, portions: 0 })) }));
    const [card] = buildProductCards([product({ modifier_groups: empty })]);
    expect(card.material_portions).toBe(0);
    expect(card.out_of_stock).toBe(false);
  });

  it('has no material figure for a product that keeps its own variant stock', () => {
    const [card] = buildProductCards([product({ stock_source: 'variant', variants: [{ id: 1, sell_price: '1', is_active: true, current_stock: 5 }] })]);
    expect(card.material_portions).toBeNull();
    expect(card.total_stock).toBe(5);
  });
});
