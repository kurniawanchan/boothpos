import { describe, it, expect, vi, beforeEach } from 'vitest';

// BUG YANG DITEMUKAN & DIPERBAIKI — every "download as PDF/image" button in
// this codebase (invoice, payment invoice, sale receipt, purchase order)
// rasterizes a live DOM element with html2canvas, then reads pixel data
// back out via canvas.toDataURL()/toBlob(). Any <img> inside that element
// whose src is cross-origin relative to the CURRENT page (a real scenario
// here — this app is reachable via a different host/IP on a tablet/LAN,
// per features 008/016, while stored image URLs are absolute to whatever
// APP_URL was configured server-side) taints the canvas, and toDataURL()
// throws — silently, because every caller wraps this in a bare
// try/catch. pdfCapture.js fixes this once, for every caller, by
// pre-fetching every <img> as a data: URI (never cross-origin) and
// swapping it into html2canvas's own cloned copy of the DOM before capture
// — never touching the live, visible document.
let capturedOnClone;
const mockCanvas = { width: 200, height: 100, toDataURL: vi.fn(() => 'data:image/png;base64,mockcanvas') };

vi.mock('html2canvas', () => ({
  default: vi.fn((el, opts) => {
    capturedOnClone = opts.onclone;
    return Promise.resolve(mockCanvas);
  }),
}));

const mockPdfSave = vi.fn();
const mockPdfAddImage = vi.fn();
vi.mock('jspdf', () => ({
  jsPDF: vi.fn(function () {
    this.addImage = mockPdfAddImage;
    this.save = mockPdfSave;
  }),
}));

import { captureElementCanvas, downloadElementAsPdf, downloadElementAsPng } from '../../resources/js/utils/pdfCapture';

function makeElWithImage(src) {
  const el = document.createElement('div');
  const img = document.createElement('img');
  img.src = src;
  el.appendChild(img);
  return el;
}

describe('pdfCapture', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    global.fetch = vi.fn(() =>
      Promise.resolve({ blob: () => Promise.resolve(new Blob(['fake-image-bytes'], { type: 'image/png' })) })
    );
  });

  it('pre-fetches every <img> as a data URL and swaps it in html2canvas\'s cloned document only', async () => {
    const el = makeElWithImage('http://example.test/logo.png');
    await captureElementCanvas(el);

    expect(global.fetch).toHaveBeenCalledWith('http://example.test/logo.png');

    // Simulate what html2canvas does internally: apply onclone to a clone
    // of the element, and confirm the image src was swapped to a data URI
    // — the LIVE element's own <img> src must be untouched.
    const clonedDoc = document.implementation.createHTMLDocument('');
    clonedDoc.body.innerHTML = el.outerHTML;
    await capturedOnClone(clonedDoc);
    const clonedImg = clonedDoc.querySelector('img');
    expect(clonedImg.src.startsWith('data:')).toBe(true);
    expect(el.querySelector('img').src).toBe('http://example.test/logo.png');
  });

  it('does not throw when an image fetch fails — falls back to leaving that image as-is', async () => {
    global.fetch = vi.fn(() => Promise.reject(new Error('network error')));
    const el = makeElWithImage('http://example.test/broken.png');

    await expect(captureElementCanvas(el)).resolves.toBe(mockCanvas);
  });

  it('downloadElementAsPdf builds a PDF sized to the canvas and saves it', async () => {
    const el = makeElWithImage('http://example.test/logo.png');
    await downloadElementAsPdf(el, 'invoice-123.pdf');

    expect(mockPdfAddImage).toHaveBeenCalledWith('data:image/png;base64,mockcanvas', 'PNG', 0, 0, 150, 75);
    expect(mockPdfSave).toHaveBeenCalledWith('invoice-123.pdf');
  });

  it('downloadElementAsPng triggers a same-page anchor download with the canvas PNG data', async () => {
    const clickSpy = vi.fn();
    const originalCreateElement = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag) => {
      const node = originalCreateElement(tag);
      if (tag === 'a') node.click = clickSpy;
      return node;
    });

    const el = makeElWithImage('http://example.test/logo.png');
    await downloadElementAsPng(el, 'invoice-123.png');

    expect(clickSpy).toHaveBeenCalled();
    document.createElement.mockRestore();
  });
});
