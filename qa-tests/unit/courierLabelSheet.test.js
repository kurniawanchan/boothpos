import { describe, it, expect, vi, beforeEach } from 'vitest';

// 069 T012 — label kurir A4, 4 per halaman (2×2), ukur-dan-tolak alih-alih memotong.
const pdf = { addPage: 0, addImage: 0, outputs: 0 };
vi.mock('jspdf', () => ({
  jsPDF: class {
    addPage() { pdf.addPage += 1; }
    addImage() { pdf.addImage += 1; }
    output() { pdf.outputs += 1; return new Blob(['%PDF'], { type: 'application/pdf' }); }
  },
}));

import {
  PAGE_W, PAGE_H, COL_WIDTHS, ROW_HEIGHTS, CONTENT_H,
  labelPages, buildCourierLabelCellHtml, buildLabelPageHtml, renderCourierLabels, LabelTooTallError,
} from '../../resources/js/utils/courierLabelSheet';

const store = { name: 'Toko Uji', address: 'Jl. Toko 1', contact_phone: '0811', contact_person: 'Budi' };
const label = (n, over = {}) => ({
  preorder_id: n,
  preorder_number: `PO-${n}`,
  courier_name: 'JNE',
  tracking_number: `TRK${n}`,
  recipient_name: `Penerima ${n}`,
  recipient_phone: `0800${n}`,
  address_line: `Alamat ${n}`,
  ...over,
});
const toDom = (html) => {
  const host = document.createElement('div');
  host.innerHTML = html;
  return host.firstElementChild;
};

describe('labelPages', () => {
  it('groups label indices four per page', () => {
    expect(labelPages(0)).toEqual([]);
    expect(labelPages(1)).toEqual([[0]]);
    expect(labelPages(4)).toEqual([[0, 1, 2, 3]]);
    expect(labelPages(5)).toEqual([[0, 1, 2, 3], [4]]);
  });
});

describe('buildCourierLabelCellHtml', () => {
  it('prints courier, tracking, recipient, sender and the order number with fixed captions', () => {
    const html = buildCourierLabelCellHtml(label(7), store);
    ['JNE', 'TRK7', 'Penerima 7', '08007', 'Alamat 7', 'Toko Uji', 'Jl. Toko 1', '0811', 'Budi', 'PO-7'].forEach((v) => expect(html).toContain(v));
    ['Courier', 'Tracking No.', 'To', 'From', 'Order'].forEach((c) => expect(html).toContain(c));
  });

  it('escapes user-typed text', () => {
    const html = buildCourierLabelCellHtml(label(1, { recipient_name: '<b>x</b> & "y"', address_line: '<img src=x onerror=alert(1)>' }), store);
    expect(html).not.toContain('<b>x</b>');
    expect(html).toContain('&lt;b&gt;x&lt;/b&gt; &amp;');
    expect(html).not.toContain('<img');
  });

  it('leaves the tracking box empty when there is no tracking number and never prints null/undefined', () => {
    const el = toDom(buildCourierLabelCellHtml(label(1, { tracking_number: null, recipient_phone: '', address_line: '' }), { name: 'S' }));
    const box = el.querySelector('[data-label-tracking]');
    expect(box).not.toBeNull();
    expect(box.textContent.trim()).toBe('');
    expect(el.outerHTML).not.toMatch(/null|undefined/);
  });

  it('never clips text', () => {
    const html = buildCourierLabelCellHtml(label(1), store) + buildLabelPageHtml([0, 1, 2, 3], Array(4).fill('<p>x</p>'));
    expect(html).not.toMatch(/overflow:\s*hidden|line-clamp|text-overflow/);
  });
});

describe('buildLabelPageHtml — integer 2×2 grid with shared cut lines', () => {
  it('is one A4 page with fixed cell sizes and borders owned as in 067', () => {
    const page = toDom(buildLabelPageHtml([0, 1, 2, 3], ['a', 'b', 'c', 'd']));
    expect(page.style.width).toBe(`${PAGE_W}px`);
    expect(page.style.height).toBe(`${PAGE_H}px`);
    expect(COL_WIDTHS[0] + COL_WIDTHS[1]).toBe(PAGE_W - 1);
    expect(ROW_HEIGHTS[0] + ROW_HEIGHTS[1]).toBe(PAGE_H);

    const cells = Array.from(page.querySelectorAll('[data-label]'));
    expect(cells).toHaveLength(4);
    const [c0, c1, c2] = cells.map((c) => c.style);
    expect([c0.width, c0.height]).toEqual(['397px', '562px']);
    expect([c0.borderLeft, c0.borderTop, c0.borderRight, c0.borderBottom].every(Boolean)).toBe(true);
    expect([c1.width, c1.borderLeft]).toEqual(['396px', '']);
    expect([c2.height, c2.borderTop]).toEqual(['561px', '']);
  });

  it('draws only the labels that exist', () => {
    const page = toDom(buildLabelPageHtml([4], ['', '', '', '', 'last']));
    expect(page.querySelectorAll('[data-label]')).toHaveLength(1);
    expect(page.textContent).toContain('last');
  });
});

describe('renderCourierLabels', () => {
  beforeEach(() => Object.assign(pdf, { addPage: 0, addImage: 0, outputs: 0 }));
  const capture = vi.fn(async () => ({ width: 1588, height: 2246, toDataURL: () => 'data:image/png;base64,AAAA' }));

  it('refuses a label taller than its cell, naming its index, before capturing anything', async () => {
    capture.mockClear();
    const err = await renderCourierLabels([label(1), label(2)], store, { measure: () => [100, CONTENT_H + 1], capture }).catch((e) => e);
    expect(err).toBeInstanceOf(LabelTooTallError);
    expect(err.index).toBe(1);
    expect(capture).not.toHaveBeenCalled();
    expect(pdf.outputs).toBe(0);
  });

  it('a label exactly the cell height fits; 5 labels make 2 pages', async () => {
    capture.mockClear();
    const labels = [1, 2, 3, 4, 5].map((n) => label(n));
    const blob = await renderCourierLabels(labels, store, { measure: () => labels.map(() => CONTENT_H), capture });
    expect(blob).toBeInstanceOf(Blob);
    expect(capture).toHaveBeenCalledTimes(2);
    expect(pdf.addPage).toBe(1);
  });
});
