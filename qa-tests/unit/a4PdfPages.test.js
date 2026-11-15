import { describe, it, expect, vi, beforeEach } from 'vitest';

// 069 T009 — perender halaman A4 bersama (diekstrak dari surat jalan 067, dipakai juga label kurir).
const pdf = { addPage: 0, addImage: [], outputs: 0 };
vi.mock('jspdf', () => ({
  jsPDF: class {
    constructor(opts) { pdf.opts = opts; }
    addPage() { pdf.addPage += 1; }
    addImage(...args) { pdf.addImage.push(args); }
    output() { pdf.outputs += 1; return new Blob(['%PDF'], { type: 'application/pdf' }); }
  },
}));

import { renderA4Pages, PDF_W_PT, PDF_H_PT } from '../../resources/js/utils/a4PdfPages';

const fakeCanvas = () => ({ width: 1588, height: 2246, toDataURL: () => 'data:image/png;base64,AAAA' });

describe('renderA4Pages (069 T009)', () => {
  beforeEach(() => {
    Object.assign(pdf, { addPage: 0, addImage: [], outputs: 0, opts: null });
  });

  it('captures each page once, in order, one PDF page per html', async () => {
    const seen = [];
    const capture = vi.fn(async (el) => { seen.push(el.dataset.page); return fakeCanvas(); });

    const blob = await renderA4Pages(['<div data-page="a"></div>', '<div data-page="b"></div>', '<div data-page="c"></div>'], { capture });

    expect(blob).toBeInstanceOf(Blob);
    expect(seen).toEqual(['a', 'b', 'c']);
    expect(pdf.addImage).toHaveLength(3);
    expect(pdf.addPage).toBe(2); // first page needs no addPage
    expect(pdf.opts).toMatchObject({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    expect(pdf.addImage[0].slice(2)).toEqual([0, 0, PDF_W_PT, PDF_H_PT]);
  });

  it('removes the off-screen host after every page and releases the canvas bitmap', async () => {
    const canvases = [];
    const capture = vi.fn(async (el) => {
      expect(document.body.contains(el)).toBe(true); // mounted while captured
      const c = fakeCanvas();
      canvases.push(c);
      return c;
    });

    await renderA4Pages(['<div data-x></div>', '<div data-x></div>'], { capture });

    expect(document.querySelectorAll('[data-x]')).toHaveLength(0);
    canvases.forEach((c) => { expect(c.width).toBe(0); expect(c.height).toBe(0); });
  });

  it('cleans up and propagates when a capture fails, producing no PDF', async () => {
    const capture = vi.fn().mockRejectedValue(new Error('boom'));

    await expect(renderA4Pages(['<div data-y></div>'], { capture })).rejects.toThrow('boom');
    expect(document.querySelectorAll('[data-y]')).toHaveLength(0);
    expect(pdf.outputs).toBe(0);
  });
});
