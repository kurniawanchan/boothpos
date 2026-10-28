import { h, render } from 'vue';
import CatalogPdfPage from '../components/catalog/CatalogPdfPage.vue';
import { captureElementCanvas } from './pdfCapture';
import { PDF_RASTER_SCALE } from './barcode';

// 049 — PDF katalog dibuat di browser, halaman demi halaman, lewat utils/pdfCapture.js (aturan proyek:
// SEMUA unduhan dokumen lewat sana — gambar ditukar ke data URL menurut `src`-nya, tak pernah menurut
// indeks). Tiap halaman A4 adalah komponen Vue berukuran tetap yang dirasterisasi lalu ditaruh ke jsPDF.
//
// Akibat yang diterima dan dicatat di research.md D4: teks di PDF berupa gambar (tidak bisa diseleksi) dan
// berkasnya lebih besar daripada PDF vektor. Imbalannya: tata letak berwarna dari token desain, nama
// non-Latin (Jepang) tampil lewat font bawaan OS, dan satu jalur unduh dokumen seperti yang lain.
// SCALE/QUALITY adalah angka awal — diukur ulang di browser sungguhan (T002/T020), bukan dianggap final.

export const PAGE_WIDTH_PX = 794; // A4 pada 96 dpi
export const PAGE_HEIGHT_PX = 1123;
export const CARDS_PER_PAGE = 8; // 2 kolom x 4 baris; tinggi kartu TETAP supaya tak ada kartu terpotong
export const PDF_SCALE = PDF_RASTER_SCALE; // satu sumber: barcode memilih lebar modul bulat terhadap skala ini
export const JPEG_QUALITY = 0.9;

function chunk(list, size) {
  const out = [];
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
  return out;
}

/**
 * Bagi katalog menjadi halaman: sampul (identitas toko + indeks penjual) lalu halaman penjual. Setiap halaman
 * milik TEPAT satu penjual; halaman lanjutan mengulang kepala penjual (FR-017) dan tiap penjual mulai di
 * halaman baru. Penjual tanpa varian dilewati.
 *
 * @returns {Array<{ type: 'cover'|'seller', number: number, total: number }>}
 */
export function paginateCatalog(catalog) {
  const sellers = (catalog?.sellers ?? []).filter((s) => s.variants?.length);
  const pages = [{ type: 'cover' }];

  sellers.forEach((seller, sellerIndex) => {
    chunk(seller.variants, CARDS_PER_PAGE).forEach((variants, i) => {
      pages.push({
        type: 'seller',
        seller: { artist_id: seller.artist_id, name: seller.name, variantCount: seller.variants.length },
        accentIndex: sellerIndex,
        variants,
        continued: i > 0,
      });
    });
  });

  const total = pages.length;
  pages.forEach((page, i) => {
    page.number = i + 1;
    page.total = total;
  });

  pages[0].index = sellers.map((seller, sellerIndex) => ({
    artist_id: seller.artist_id,
    name: seller.name,
    count: seller.variants.length,
    accentIndex: sellerIndex,
    startPage: pages.find((p) => p.type === 'seller' && p.seller.artist_id === seller.artist_id).number,
  }));

  return pages;
}

/** katalog-produk-<toko>-<yyyy-mm-dd>.pdf; nama toko non-Latin/kosong jatuh ke "toko". */
export function catalogFilename(store, generatedAt, extension = 'pdf') {
  const slug = String(store?.name ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'toko';
  const date = String(generatedAt ?? '').slice(0, 10) || new Date().toISOString().slice(0, 10);
  return `katalog-produk-${slug}-${date}.${extension}`;
}

function mountPage(page, catalog, appContext) {
  const container = document.createElement('div');
  container.style.position = 'fixed';
  container.style.left = '-9999px';
  container.style.top = '0';
  container.style.width = `${PAGE_WIDTH_PX}px`;
  document.body.appendChild(container);
  const vnode = h(CatalogPdfPage, { page, store: catalog.store, generatedAt: catalog.generated_at });
  vnode.appContext = appContext;
  render(vnode, container);
  return { container, dispose: () => { render(null, container); container.remove(); } };
}

/**
 * Buat dan unduh PDF. Berurutan, bukan paralel: merasterisasi puluhan kanvas sekaligus membekukan tab.
 * `signal` (AbortSignal) menghentikan di antara halaman; wadah offscreen SELALU dibersihkan, juga saat gagal.
 */
export async function generateCatalogPdf(catalog, { appContext, onProgress, signal } = {}) {
  const pages = paginateCatalog(catalog);
  if (pages.length < 2) throw new Error('Katalog kosong');

  const { jsPDF } = await import('jspdf');
  const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });

  for (let i = 0; i < pages.length; i += 1) {
    if (signal?.aborted) throw new DOMException('Dibatalkan', 'AbortError');
    onProgress?.(i + 1, pages.length);
    await new Promise((resolve) => setTimeout(resolve, 0)); // beri UI kesempatan menggambar kemajuan

    const { container, dispose } = mountPage(pages[i], catalog, appContext);
    let canvas;
    try {
      canvas = await captureElementCanvas(container, { scale: PDF_SCALE });
    } finally {
      dispose();
    }

    if (i > 0) pdf.addPage('a4', 'portrait');
    pdf.addImage(canvas.toDataURL('image/jpeg', JPEG_QUALITY), 'JPEG', 0, 0, 210, 297);
  }

  pdf.save(catalogFilename(catalog.store, catalog.generated_at));
  return pages.length;
}
