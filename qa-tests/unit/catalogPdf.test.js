import { describe, it, expect, vi, beforeEach } from 'vitest';

const addImage = vi.fn();
const addPage = vi.fn();
const save = vi.fn();
vi.mock('jspdf', () => ({ jsPDF: class { addImage(...a) { addImage(...a); } addPage(...a) { addPage(...a); } save(...a) { save(...a); } } }));
const captureElementCanvas = vi.fn();
vi.mock('../../resources/js/utils/pdfCapture', () => ({ captureElementCanvas: (...a) => captureElementCanvas(...a) }));
vi.mock('../../resources/js/components/catalog/CatalogPdfPage.vue', () => ({ default: { props: ['page'], render() { return null; } } }));

import { paginateCatalog, generateCatalogPdf, catalogFilename, CARDS_PER_PAGE } from '../../resources/js/utils/catalogPdf';
import { accentAt, ACCENTS } from '../../resources/js/utils/catalogAccents';

const variants = (n, prefix = 'V') => Array.from({ length: n }, (_, i) => ({ variant_id: `${prefix}${i}`, code: `${prefix}-${i}`, product_name: 'P', variant_name: `n${i}`, sell_price: '1000.00', barcode: null }));
const seller = (id, name, n) => ({ artist_id: id, name, variants: variants(n, `S${id}`) });
const catalog = (sellers) => ({ generated_at: '2026-10-28T11:20:00+07:00', store: { name: 'Toko Kita' }, totals: {}, sellers });

describe('paginateCatalog (049)', () => {
  it('always starts with the cover and gives a seller with N variants ceil(N / 8) pages', () => {
    for (const [n, pages] of [[1, 1], [8, 1], [9, 2], [16, 2], [17, 3]]) {
      const result = paginateCatalog(catalog([seller(1, 'A', n)]));
      expect(result[0].type).toBe('cover');
      expect(result.filter((p) => p.type === 'seller')).toHaveLength(pages);
    }
    expect(CARDS_PER_PAGE).toBe(8);
  });

  it('puts every variant on exactly one page and never splits or exceeds a page', () => {
    const result = paginateCatalog(catalog([seller(1, 'A', 17), seller(2, 'B', 3)]));
    const cards = result.filter((p) => p.type === 'seller');

    expect(cards.every((p) => p.variants.length <= CARDS_PER_PAGE)).toBe(true);
    expect(cards.flatMap((p) => p.variants).map((v) => v.variant_id).sort()).toEqual([...variants(17, 'S1'), ...variants(3, 'S2')].map((v) => v.variant_id).sort());
  });

  it('gives each page one seller, starts every seller on a fresh page and marks continuation pages', () => {
    const result = paginateCatalog(catalog([seller(1, 'A', 9), seller(2, 'B', 2)]));
    const [, a1, a2, b1] = result;

    expect([a1.seller.name, a2.seller.name, b1.seller.name]).toEqual(['A', 'A', 'B']);
    expect([a1.continued, a2.continued, b1.continued]).toEqual([false, true, false]);
    expect(a1.seller.variantCount).toBe(9); // kepala memuat jumlah penjual, bukan jumlah di halaman itu
  });

  it('skips sellers without variants and yields only the cover for an empty catalog', () => {
    expect(paginateCatalog(catalog([seller(1, 'A', 0), seller(2, 'B', 1)])).filter((p) => p.type === 'seller').map((p) => p.seller.name)).toEqual(['B']);
    expect(paginateCatalog(catalog([]))).toHaveLength(1);
  });

  it('numbers pages and gives the cover an index with each seller\'s count and first page', () => {
    const result = paginateCatalog(catalog([seller(1, 'A', 9), seller(2, 'B', 2)]));

    expect(result.map((p) => [p.number, p.total])).toEqual([[1, 4], [2, 4], [3, 4], [4, 4]]);
    expect(result[0].index).toEqual([
      { artist_id: 1, name: 'A', count: 9, accentIndex: 0, startPage: 2 },
      { artist_id: 2, name: 'B', count: 2, accentIndex: 1, startPage: 4 },
    ]);
  });

  it('cycles the accent colour per seller and repeats it on that seller\'s continuation pages', () => {
    const sellers = Array.from({ length: ACCENTS.length + 1 }, (_, i) => seller(i + 1, `S${i}`, 9));
    const pages = paginateCatalog(catalog(sellers)).filter((p) => p.type === 'seller');

    expect(accentAt(ACCENTS.length)).toBe(accentAt(0)); // putaran penuh kembali ke aksen pertama
    expect(pages[0].accentIndex).toBe(pages[1].accentIndex); // halaman lanjutan penjual yang sama
    expect(pages[2].accentIndex).not.toBe(pages[0].accentIndex);
  });
});

describe('catalogFilename (049)', () => {
  it('slugifies the store name and uses the generation date', () => {
    expect(catalogFilename({ name: 'Demo Sakana Fridge!' }, '2026-10-28T11:20:00+07:00')).toBe('katalog-produk-demo-sakana-fridge-2026-10-28.pdf');
    expect(catalogFilename({ name: 'Toko' }, '2026-10-28T11:20:00+07:00', 'xlsx')).toBe('katalog-produk-toko-2026-10-28.xlsx');
  });

  it('falls back to "toko" for a non-Latin or missing store name', () => {
    expect(catalogFilename({ name: 'セラー' }, '2026-10-28T00:00:00Z')).toBe('katalog-produk-toko-2026-10-28.pdf');
    expect(catalogFilename(null, '2026-10-28T00:00:00Z')).toBe('katalog-produk-toko-2026-10-28.pdf');
  });
});

describe('generateCatalogPdf (049)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
    captureElementCanvas.mockResolvedValue({ toDataURL: () => 'data:image/jpeg;base64,AAAA' });
  });

  it('adds one JPEG page per catalog page, reports progress, saves once and leaves no offscreen container behind', async () => {
    const onProgress = vi.fn();
    const total = await generateCatalogPdf(catalog([seller(1, 'A', 9)]), { appContext: undefined, onProgress });

    expect(total).toBe(3); // sampul + 2 halaman penjual
    expect(addImage).toHaveBeenCalledTimes(3);
    expect(addImage.mock.calls.every((c) => c[1] === 'JPEG' && c[2] === 0 && c[3] === 0 && c[4] === 210 && c[5] === 297)).toBe(true);
    expect(addPage).toHaveBeenCalledTimes(2);
    expect(onProgress.mock.calls).toEqual([[1, 3], [2, 3], [3, 3]]);
    expect(save).toHaveBeenCalledWith('katalog-produk-toko-kita-2026-10-28.pdf');
    expect(document.querySelectorAll('div[style*="-9999px"]')).toHaveLength(0);
  });

  it('captures through the shared pdfCapture util with the configured scale', async () => {
    await generateCatalogPdf(catalog([seller(1, 'A', 1)]), {});

    expect(captureElementCanvas).toHaveBeenCalledTimes(2);
    expect(captureElementCanvas.mock.calls[0][1]).toEqual({ scale: expect.any(Number) });
  });

  it('refuses an empty catalog without touching the PDF', async () => {
    await expect(generateCatalogPdf(catalog([]), {})).rejects.toThrow();
    expect(save).not.toHaveBeenCalled();
  });

  it('stops between pages when aborted and does not save a partial file', async () => {
    const controller = new AbortController();
    captureElementCanvas.mockImplementation(async () => { controller.abort(); return { toDataURL: () => 'x' }; });

    await expect(generateCatalogPdf(catalog([seller(1, 'A', 20)]), { signal: controller.signal })).rejects.toMatchObject({ name: 'AbortError' });
    expect(save).not.toHaveBeenCalled();
    expect(document.querySelectorAll('div[style*="-9999px"]')).toHaveLength(0);
  });

  it('cleans up the offscreen container even when a page fails to capture', async () => {
    captureElementCanvas.mockRejectedValue(new Error('boom'));

    await expect(generateCatalogPdf(catalog([seller(1, 'A', 1)]), {})).rejects.toThrow('boom');
    expect(document.querySelectorAll('div[style*="-9999px"]')).toHaveLength(0);
    expect(save).not.toHaveBeenCalled();
  });
});
