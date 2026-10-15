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

const LOGO = 'http://example.test/store-logo.png';
const QR = 'http://example.test/payment-qr.jpg';
const AVATAR = 'http://example.test/avatar.png';

/** Dokumen seperti faktur: logo di header, lalu kode QR di bagian pembayaran. */
function makeInvoiceEl(srcs = [LOGO, QR]) {
  const el = document.createElement('div');
  srcs.forEach((src, i) => {
    const img = document.createElement('img');
    img.setAttribute('src', src);
    img.dataset.slot = i === 0 ? 'logo' : `qr${i}`;
    el.appendChild(img);
  });
  return el;
}

/** fetch palsu: isi blob = URL-nya sendiri, jadi data URL hasilnya bisa dibaca dan dicocokkan. */
function fetchEchoingTheUrl(failFor = []) {
  return vi.fn((url) =>
    failFor.includes(url)
      ? Promise.reject(new Error('network error'))
      : Promise.resolve({ blob: () => Promise.resolve(new Blob([url], { type: 'image/png' })) })
  );
}

const decode = (dataUrl) => atob(dataUrl.split(',')[1]);

/**
 * Meniru apa yang dilakukan html2canvas 1.4.1: onclone(documentClone, referenceElement)
 * dipanggil dengan klon SELURUH halaman (bukan hanya elemen yang difoto) dan klon elemen acuannya.
 * `before`/`after` = gambar lain di halaman (avatar, thumbnail produk, panel yang terbuka).
 */
async function runOnClone(el, { before = [], after = [], passReference = true } = {}) {
  const clonedDoc = document.implementation.createHTMLDocument('');
  const addDecoys = (list) => list.forEach((src) => {
    const img = clonedDoc.createElement('img');
    img.setAttribute('src', src);
    img.dataset.decoy = 'true';
    clonedDoc.body.appendChild(img);
  });
  addDecoys(before);
  const root = clonedDoc.importNode(el, true);
  clonedDoc.body.appendChild(root);
  addDecoys(after);
  await capturedOnClone(clonedDoc, passReference ? root : undefined);
  return { clonedDoc, root };
}

describe('pdfCapture', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    global.fetch = fetchEchoingTheUrl();
  });

  it('pre-fetches every <img> as a data URL and swaps it in html2canvas\'s cloned document only', async () => {
    const el = makeInvoiceEl([LOGO]);
    await captureElementCanvas(el);

    expect(global.fetch).toHaveBeenCalledWith(LOGO);

    const { root } = await runOnClone(el);
    const clonedImg = root.querySelector('img');
    expect(clonedImg.getAttribute('src').startsWith('data:')).toBe(true);
    // elemen HIDUP tak boleh tersentuh
    expect(el.querySelector('img').getAttribute('src')).toBe(LOGO);
  });

  // BUG YANG DITEMUKAN & DIPERBAIKI — dulu gambar dicocokkan lewat INDEKS terhadap
  // `clonedDoc.querySelectorAll('img')`, padahal argumen pertama onclone adalah klon
  // SELURUH halaman: satu gambar lain di halaman (avatar, thumbnail) menggeser semua
  // indeks, sehingga slot logo menerima data gambar QR.
  it('gives every image its OWN bytes even when other images precede the document on the page', async () => {
    const el = makeInvoiceEl([LOGO, QR]);
    await captureElementCanvas(el);

    const { root } = await runOnClone(el, { before: [AVATAR] });

    const logo = root.querySelector('[data-slot=logo]');
    const qr = root.querySelector('[data-slot=qr1]');
    expect(decode(logo.getAttribute('src'))).toBe(LOGO);
    expect(decode(qr.getAttribute('src'))).toBe(QR);
  });

  it('is not shifted by images before AND after the document', async () => {
    const el = makeInvoiceEl([LOGO, QR]);
    await captureElementCanvas(el);

    const { root, clonedDoc } = await runOnClone(el, { before: [AVATAR, AVATAR], after: [AVATAR] });

    expect(decode(root.querySelector('[data-slot=logo]').getAttribute('src'))).toBe(LOGO);
    expect(decode(root.querySelector('[data-slot=qr1]').getAttribute('src'))).toBe(QR);
    // gambar lain di halaman tidak pernah menerima data gambar milik dokumen
    clonedDoc.querySelectorAll('img[data-decoy]').forEach((img) => expect(img.getAttribute('src')).toBe(AVATAR));
  });

  it('only touches images inside the cloned reference element when html2canvas provides it', async () => {
    const el = makeInvoiceEl([LOGO]);
    await captureElementCanvas(el);

    // gambar lain di halaman yang KEBETULAN memakai URL yang sama dengan logo
    const { clonedDoc } = await runOnClone(el, { before: [LOGO] });

    expect(clonedDoc.querySelector('img[data-decoy]').getAttribute('src')).toBe(LOGO);
  });

  it('still never gives an image another URL\'s bytes when no reference element is passed', async () => {
    const el = makeInvoiceEl([LOGO, QR]);
    await captureElementCanvas(el);

    const { clonedDoc } = await runOnClone(el, { before: [AVATAR], passReference: false });

    expect(decode(clonedDoc.querySelector('[data-slot=logo]').getAttribute('src'))).toBe(LOGO);
    expect(decode(clonedDoc.querySelector('[data-slot=qr1]').getAttribute('src'))).toBe(QR);
    expect(clonedDoc.querySelector('img[data-decoy]').getAttribute('src')).toBe(AVATAR);
  });

  it('does not throw when an image fetch fails — that image keeps its own src and the others are still swapped', async () => {
    global.fetch = fetchEchoingTheUrl([QR]);
    const el = makeInvoiceEl([LOGO, QR]);

    await expect(captureElementCanvas(el)).resolves.toBe(mockCanvas);

    const { root } = await runOnClone(el, { before: [AVATAR] });
    expect(decode(root.querySelector('[data-slot=logo]').getAttribute('src'))).toBe(LOGO);
    // FR-006: gambar yang gagal dimuat TIDAK PERNAH diganti gambar lain
    expect(root.querySelector('[data-slot=qr1]').getAttribute('src')).toBe(QR);
  });

  it('fetches a URL used by several images only once and gives all of them the same bytes', async () => {
    const el = makeInvoiceEl([QR, QR]);
    await captureElementCanvas(el);

    expect(global.fetch).toHaveBeenCalledTimes(1);
    const { root } = await runOnClone(el, { before: [AVATAR] });
    root.querySelectorAll('img').forEach((img) => expect(decode(img.getAttribute('src'))).toBe(QR));
  });

  // 029 US2 — hasil unduhan tidak boleh bergantung pada banyaknya gambar lain di halaman.
  it.each([0, 1, 20])('gives the same result with %i other images before the document', async (count) => {
    const el = makeInvoiceEl([LOGO, QR]);
    await captureElementCanvas(el);

    const { root } = await runOnClone(el, { before: Array.from({ length: count }, () => AVATAR) });

    expect(decode(root.querySelector('[data-slot=logo]').getAttribute('src'))).toBe(LOGO);
    expect(decode(root.querySelector('[data-slot=qr1]').getAttribute('src'))).toBe(QR);
  });

  it('leaves other page images untouched even when they share a URL with the document\'s images', async () => {
    const el = makeInvoiceEl([LOGO, QR]);
    await captureElementCanvas(el);

    const { clonedDoc, root } = await runOnClone(el, { before: [QR, LOGO, AVATAR], after: [QR] });

    expect(decode(root.querySelector('[data-slot=logo]').getAttribute('src'))).toBe(LOGO);
    expect(decode(root.querySelector('[data-slot=qr1]').getAttribute('src'))).toBe(QR);
    // gambar lain di halaman tidak disentuh sama sekali, jadi tetap memakai src aslinya
    const decoySrcs = [...clonedDoc.querySelectorAll('img[data-decoy]')].map((img) => img.getAttribute('src'));
    expect(decoySrcs).toEqual([QR, LOGO, AVATAR, QR]);
  });

  it('fetches exactly once per distinct image URL, however many images or other page images exist', async () => {
    const el = makeInvoiceEl([LOGO, QR, QR, LOGO, QR]);
    await captureElementCanvas(el);
    await runOnClone(el, { before: Array.from({ length: 20 }, () => AVATAR) });

    expect(global.fetch).toHaveBeenCalledTimes(2);
    expect(global.fetch).toHaveBeenCalledWith(LOGO);
    expect(global.fetch).toHaveBeenCalledWith(QR);
  });

  it('resolves for a document without any image', async () => {
    const el = document.createElement('div');
    el.textContent = 'tanpa gambar';

    await expect(captureElementCanvas(el)).resolves.toBe(mockCanvas);
    expect(global.fetch).not.toHaveBeenCalled();
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
