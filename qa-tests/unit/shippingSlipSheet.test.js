import { describe, it, expect } from 'vitest';
import {
  PAGE_H, PAGE_W, COL_WIDTHS, layoutSlipRows, SlipTooTallError,
  buildShippingSlipCellHtml, buildSheetPageHtml,
} from '../../resources/js/utils/shippingSlipSheet';

const flat = (pages) => pages.flatMap((p) => p.rows.flatMap((r) => r.slips));
const same = (n, h) => Array.from({ length: n }, () => h);

describe('layoutSlipRows (067 T005)', () => {
  it('7 slips on one page become rows of 3 / 3 / 1', () => {
    const { pages } = layoutSlipRows(same(7, 100));
    expect(pages).toHaveLength(1);
    expect(pages[0].rows.map((r) => r.slips.length)).toEqual([3, 3, 1]);
  });

  it('every slip index appears exactly once, in order, across pages', () => {
    const { pages } = layoutSlipRows(same(40, 200));
    expect(pages.length).toBeGreaterThan(1);
    expect(flat(pages)).toEqual(Array.from({ length: 40 }, (_, i) => i));
  });

  it('a row height is the tallest slip of that row only', () => {
    const { pages } = layoutSlipRows([100, 300, 120, 90, 80, 70]);
    expect(pages[0].rows.map((r) => r.height)).toEqual([300, 90]);
  });

  it('starts a new page on overflow and never splits a row', () => {
    // capacity 1122: 3 rows of 400 = 1200 > 1122 -> 2 rows then a new page
    const { pages } = layoutSlipRows(same(9, 400));
    expect(pages.map((p) => p.rows.length)).toEqual([2, 1]);
    pages.forEach((p) => p.rows.forEach((r) => expect(r.slips.length).toBeLessThanOrEqual(3)));
  });

  it('a row that fits exactly stays on the page', () => {
    const { pages } = layoutSlipRows([PAGE_H - 1], {});
    expect(pages).toHaveLength(1);
  });

  it('two rows that together fill the page exactly (incl. the top border) share one page', () => {
    // capacity = 1123 - 1 = 1122 = 561 + 561; one more px must move to a new page
    expect(layoutSlipRows([561, 561], { cols: 1 }).pages).toHaveLength(1);
    expect(layoutSlipRows([561, 562], { cols: 1 }).pages).toHaveLength(2);
  });

  it('throws SlipTooTallError for a row taller than a page, never truncating', () => {
    expect(() => layoutSlipRows([100, PAGE_H, 100])).toThrow(SlipTooTallError);
  });

  it('returns no pages for no slips', () => {
    expect(layoutSlipRows([]).pages).toEqual([]);
  });

  it('a page never exceeds the page height (rows + 1px top border)', () => {
    const heights = Array.from({ length: 200 }, (_, i) => 60 + ((i * 37) % 240));
    const { pages } = layoutSlipRows(heights);
    pages.forEach((p) => {
      const sum = p.rows.reduce((a, r) => a + r.height, 0) + 1;
      expect(sum).toBeLessThanOrEqual(PAGE_H);
    });
    expect(flat(pages)).toHaveLength(200);
  });
});

describe('buildShippingSlipCellHtml (067 T006)', () => {
  const invoice = {
    preorder_number: 'PO-TEST-0001',
    store_identity: { name: 'Test Store', address: 'Jl. Contoh 1', contact_phone: '0000', contact_person: 'Tester' },
    customer: { name: 'Test Recipient', phone: '0000', address: 'Jl. Uji 2' },
  };

  it('contains the number and the From / To blocks with the existing labels', () => {
    const html = buildShippingSlipCellHtml(invoice);
    expect(html).toContain('Shipping Info');
    expect(html).toContain('PO-TEST-0001');
    expect(html).toContain('From');
    expect(html).toContain('To');
    expect(html).toContain('Test Recipient');
    expect(html).toContain('Jl. Uji 2');
    expect(html).toContain('Test Store');
  });

  it('escapes every user-typed value', () => {
    const html = buildShippingSlipCellHtml({
      preorder_number: '<img src=x onerror=1>',
      store_identity: { name: '<b>S</b>', address: '<i>a</i>' },
      customer: { name: '<script>alert(1)</script>', phone: '"><x>', address: "'<y>" },
    });
    const host = document.createElement('div');
    host.innerHTML = html;
    expect(host.querySelector('script, img, b, i, x, y')).toBeNull();
    expect(html).toContain('&lt;script&gt;');
  });

  it('draws no outer border or margin itself (the page grid draws the lines)', () => {
    const host = document.createElement('div');
    host.innerHTML = buildShippingSlipCellHtml(invoice);
    const root = host.firstElementChild;
    expect(root.style.border).toBe('');
    expect(root.style.margin).toBe('');
    expect(root.style.height).toBe('');
    expect(html(root)).not.toMatch(/overflow:\s*hidden|line-clamp|text-overflow/);
  });

  it('does not throw with missing customer / store identity', () => {
    expect(() => buildShippingSlipCellHtml({ preorder_number: 'X' })).not.toThrow();
    expect(() => buildShippingSlipCellHtml(null)).not.toThrow();
  });
});

function html(el) { return el.outerHTML; }

describe('buildSheetPageHtml (067 T007)', () => {
  const cells = Array.from({ length: 7 }, (_, i) => `<span>slip ${i}</span>`);
  const { pages } = layoutSlipRows([100, 100, 100, 150, 100, 100, 90]); // rows 3/3/1 -> 100 / 150 / 90
  const mount = (page) => {
    const host = document.createElement('div');
    host.innerHTML = buildSheetPageHtml(page, cells);
    return host.firstElementChild;
  };

  it('is exactly 794x1123, white, zero padding and margin', () => {
    const el = mount(pages[0]);
    expect(el.style.width).toBe(`${PAGE_W}px`);
    expect(el.style.height).toBe(`${PAGE_H}px`);
    expect(el.style.padding).toBe('0px');
    expect(el.style.margin).toBe('0px');
    expect(el.style.background).toMatch(/#fff|rgb\(255, 255, 255\)/);
  });

  it('draws one cell per real slip and no empty boxes for a partial row', () => {
    const el = mount(pages[0]);
    expect(el.querySelectorAll('[data-slip]')).toHaveLength(7);
    expect(Array.from(el.querySelectorAll('[data-row]')).map((r) => r.children.length)).toEqual([3, 3, 1]);
  });

  it('gives each cell border-box sizing and a 1px solid black right + bottom border', () => {
    const cell = mount(pages[0]).querySelector('[data-slip="4"]');
    expect(cell.style.boxSizing).toBe('border-box');
    expect(cell.style.borderRight).toMatch(/1px solid (#000|rgb\(0, 0, 0\))/);
    expect(cell.style.borderBottom).toMatch(/1px solid (#000|rgb\(0, 0, 0\))/);
    expect(cell.style.borderLeft).toBe('');
    expect(cell.style.borderTop).toBe('');
  });

  it('adds a left border only in the first column and a top border only in the first row', () => {
    const el = mount(pages[0]);
    const at = (i) => el.querySelector(`[data-slip="${i}"]`);
    expect(at(0).style.borderLeft).not.toBe('');
    expect(at(1).style.borderLeft).toBe('');
    expect(at(3).style.borderLeft).not.toBe('');
    expect(at(0).style.borderTop).not.toBe('');
    expect(at(2).style.borderTop).not.toBe('');
    expect(at(3).style.borderTop).toBe('');
  });

  it('uses the 265/264/264 integer column widths', () => {
    const cells3 = Array.from(mount(pages[0]).querySelector('[data-row]').children);
    expect(cells3.map((c) => c.style.width)).toEqual(COL_WIDTHS.map((w) => `${w}px`));
    expect(COL_WIDTHS.reduce((a, b) => a + b, 0)).toBeLessThanOrEqual(PAGE_W);
  });

  it('gives every cell in a row the same height (the row height; +1 for the first row top border)', () => {
    const rows = Array.from(mount(pages[0]).querySelectorAll('[data-row]'));
    const heights = rows.map((r) => Array.from(r.children).map((c) => c.style.height));
    heights.forEach((hs) => expect(new Set(hs).size).toBe(1));
    expect(rows.map((r) => r.style.height)).toEqual(['101px', '150px', '90px']);
  });
});
