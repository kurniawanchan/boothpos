import { describe, it, expect } from 'vitest';
import {
  addToCart, buildOrderPayload, buildSelfServiceCards, cartTotals, lineKey, makeLine, needsPicker, parseDraft,
  removeLine, serializeDraft, setLineQty, unitPrice,
} from '../../resources/js/utils/selfServiceCart';

const group = (over = {}) => ({
  id: 7, name: 'Level Pedas', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, portion_per_option: false,
  options: [{ id: 71, name: 'Pedas', price_delta: '2000.00', min_qty: 1, max_qty: 1 }, { id: 72, name: 'Sedang', price_delta: '0.00', min_qty: 1, max_qty: 1 }],
  ...over,
});
const product = (over = {}) => ({
  id: 1, name: 'Mie Goreng', description: null, category_id: 3, category_name: 'Makanan', image_url: null, sold_out: false,
  variants: [{ id: 11, name: 'Standard', price: '15000.00' }], modifier_groups: [], ...over,
});

describe('buildSelfServiceCards', () => {
  it('derives price range, variant count and sold-out from the public payload', () => {
    const [card] = buildSelfServiceCards([product({ variants: [{ id: 1, name: 'S', price: '10000.00' }, { id: 2, name: 'L', price: '14000.00' }] })]);

    expect(card).toMatchObject({ product_id: 1, min_price: 10000, max_price: 14000, variant_count: 2, sold_out: false });
  });

  it('treats a product without variants as sold out', () => {
    expect(buildSelfServiceCards([product({ variants: [] })])[0].sold_out).toBe(true);
  });

  it('only asks for the picker when there are several variants or modifier groups', () => {
    expect(needsPicker(buildSelfServiceCards([product()])[0])).toBe(false);
    expect(needsPicker(buildSelfServiceCards([product({ modifier_groups: [group()] })])[0])).toBe(true);
    expect(needsPicker(buildSelfServiceCards([product({ variants: [{ id: 1, name: 'A', price: '1.00' }, { id: 2, name: 'B', price: '2.00' }] })])[0])).toBe(true);
  });
});

describe('lines', () => {
  const card = buildSelfServiceCards([product({ modifier_groups: [group()] })])[0];
  const variant = card.variants[0];

  it('prices a line from the variant plus the chosen modifier delta', () => {
    const line = makeLine({ card, variant, selection: { 7: [71] }, quantities: { 71: 1 }, qty: 2 });

    expect(line.unit_price).toBe(17000);
    expect(line.modifiers).toEqual(['Pedas']);
    expect(line.name).toBe('Mie Goreng'); // varian tunggal "Standard" = nama produk saja
  });

  it('applies the per-portion rule like the POS does', () => {
    const g = group({ selection_type: 'multiple', is_required: false, min_select: 0, max_select: 3, portion_per_option: true,
      options: [{ id: 1, name: 'A', price_delta: '0.00', min_qty: 1, max_qty: 1 }, { id: 2, name: 'B', price_delta: '0.00', min_qty: 1, max_qty: 1 }] });

    // Dua pilihan di grup porsi = dua porsi: satu harga dasar tambahan.
    expect(unitPrice({ price: '10000.00' }, [g], { [g.id]: [1, 2] }, {})).toBe(20000);
  });

  it('merges identical lines and splits lines that differ by note or choice', () => {
    const a = makeLine({ card, variant, selection: { 7: [71] }, quantities: { 71: 1 } });
    const same = makeLine({ card, variant, selection: { 7: [71] }, quantities: { 71: 1 } });
    const other = makeLine({ card, variant, selection: { 7: [72] }, quantities: { 72: 1 } });
    const noted = makeLine({ card, variant, selection: { 7: [71] }, quantities: { 71: 1 }, note: 'tanpa daun bawang' });

    let lines = addToCart([], a);
    lines = addToCart(lines, same);
    lines = addToCart(lines, other);
    lines = addToCart(lines, noted);

    expect(lines).toHaveLength(3);
    expect(lines.find((l) => l.key === a.key).qty).toBe(2);
  });

  it('changes quantity, caps it at 99 and removes at zero', () => {
    const line = makeLine({ card, variant, selection: { 7: [71] }, quantities: { 71: 1 } });
    let lines = addToCart([], line);

    lines = setLineQty(lines, line.key, 150);
    expect(lines[0].qty).toBe(99);
    expect(setLineQty(lines, line.key, 0)).toEqual([]);
    expect(removeLine(lines, line.key)).toEqual([]);
  });

  it('builds a stable key regardless of option order', () => {
    expect(lineKey(1, 'x', [2, 1], { 1: 1, 2: 1 })).toBe(lineKey(1, 'x', [1, 2], { 1: 1, 2: 1 }));
  });
});

describe('cartTotals', () => {
  it('rounds tax to whole rupiah per order, half up', () => {
    const lines = [{ unit_price: 15000, qty: 1 }, { unit_price: 1050, qty: 1 }];

    expect(cartTotals(lines, 10)).toEqual({ count: 2, subtotal: 16050, tax: 1605, total: 17655 });
    expect(cartTotals([{ unit_price: 105, qty: 1 }], 5).tax).toBe(5); // 5,25 → 5
    expect(cartTotals([{ unit_price: 110, qty: 1 }], 5).tax).toBe(6); // 5,5 → 6
  });

  it('has no tax when the rate is zero and never any discount', () => {
    const totals = cartTotals([{ unit_price: 20000, qty: 2 }], 0);

    expect(totals).toEqual({ count: 2, subtotal: 40000, tax: 0, total: 40000 });
    expect(Object.keys(totals)).not.toContain('discount');
  });
});

describe('buildOrderPayload', () => {
  const card = buildSelfServiceCards([product({ modifier_groups: [group()] })])[0];

  it('never carries prices, discounts, outlet or table', () => {
    const line = makeLine({ card, variant: card.variants[0], selection: { 7: [71] }, quantities: { 71: 1 }, qty: 2, note: 'pedas' });
    const payload = buildOrderPayload({ lines: [line], serviceType: 'takeaway', proofToken: 'p', localRef: 'r' });

    expect(payload).toEqual({
      local_ref: 'r', service_type: 'takeaway', proof_token: 'p',
      items: [{ variant_id: 11, qty: 2, note: 'pedas', modifier_option_ids: [71] }],
    });
    const raw = JSON.stringify(payload);
    for (const forbidden of ['price', 'discount', 'total', 'outlet', 'table']) expect(raw).not.toContain(forbidden);
  });

  it('sends counted choices as modifier_selections', () => {
    const g = group({ selection_type: 'multiple', is_required: false, min_select: 0, max_select: 2,
      options: [{ id: 5, name: 'Telur', price_delta: '3000.00', min_qty: 1, max_qty: 3 }] });
    const c = buildSelfServiceCards([product({ modifier_groups: [g] })])[0];
    const line = makeLine({ card: c, variant: c.variants[0], selection: { [g.id]: [5] }, quantities: { 5: 2 } });

    expect(buildOrderPayload({ lines: [line], serviceType: 'dine_in', proofToken: 'p', localRef: 'r' }).items[0])
      .toEqual({ variant_id: 11, qty: 1, modifier_selections: [{ option_id: 5, qty: 2 }] });
  });
});

describe('draft', () => {
  const line = { key: 'k', variant_id: 11, qty: 2, unit_price: 15000, name: 'Mie', note: '', option_ids: [], quantities: {}, modifiers: [] };

  it('round-trips and keeps the service type and local ref', () => {
    const raw = serializeDraft({ lines: [line], serviceType: 'takeaway', localRef: 'abc' }, 1000);

    expect(parseDraft(raw, 2000)).toEqual({ lines: [line], serviceType: 'takeaway', localRef: 'abc' });
  });

  it('drops expired, malformed and corrupted drafts without throwing', () => {
    const raw = serializeDraft({ lines: [line], serviceType: 'dine_in', localRef: 'abc' }, 0);

    expect(parseDraft(raw, 13 * 60 * 60 * 1000)).toBeNull();
    expect(parseDraft('{bukan json', 0)).toBeNull();
    expect(parseDraft('', 0)).toBeNull();
    expect(parseDraft(JSON.stringify({ lines: 'x', localRef: 'a', savedAt: 0 }), 0)).toBeNull();
  });

  it('discards corrupted lines and falls back to dine-in', () => {
    const raw = JSON.stringify({ lines: [line, { variant_id: 'x', qty: 1 }, null, { ...line, qty: 0 }], serviceType: 'weird', localRef: 'a', savedAt: 5 });

    expect(parseDraft(raw, 6)).toEqual({ lines: [line], serviceType: 'dine_in', localRef: 'a' });
  });
});
