/**
 * Surat jalan massal pada lembar A4 siap potong (feature 067).
 *
 * Unduh massal surat jalan dulu menghasilkan satu PDF per surat jalan di dalam
 * ZIP. Sekarang hasilnya SATU PDF A4: surat jalan disusun 3 kolom, masing-masing
 * berbingkai 1 px hitam, saling menempel tanpa margin/jarak, sehingga pemilik
 * bisa langsung memotong mengikuti garis.
 *
 * Keputusan desain (lihat specs/067-bulk-shipping-slips-a4-grid/research.md):
 *  - D1  satu halaman = satu rasterisasi (bukan satu per surat jalan).
 *  - D2  grid bilangan bulat 265+264+264 px pada halaman 794x1123 px; tiap kotak
 *        hanya memiliki garis kanan+bawah (kolom pertama juga kiri, baris
 *        pertama halaman juga atas) sehingga dua tetangga berbagi SATU garis.
 *        Pada skala 2 setiap garis tepat 2 piksel perangkat -> tajam.
 *  - D3  tinggi baris = surat jalan tertinggi di baris itu, DIUKUR dari markup
 *        asli. Tidak pernah tinggi tetap + overflow:hidden / line-clamp: alamat
 *        tak boleh terpotong (jebakan html2canvas fitur 049).
 *  - D4  pemaketan halaman = fungsi murni `layoutSlipRows` (jsdom tak punya
 *        layout, jadi pengukur disuntikkan).
 *  - D5  baris lebih tinggi dari satu halaman = galat (SlipTooTallError), bukan
 *        pemotongan diam-diam.
 */
import { escapeHtml } from './invoiceDocument';
import { captureElementCanvas } from './pdfCapture';

/** A4 pada 96 dpi (CSS px). */
export const PAGE_W = 794;
export const PAGE_H = 1123;
export const COLS = 3;
/** 265+264+264 = 793 (sisa 1 px di tepi kanan, tak terlihat). Bilangan bulat -> garis tajam. */
export const COL_WIDTHS = [265, 264, 264];
/** Lebar isi (di dalam garis) tiap kolom: 265-2 (kiri+kanan) dan 264-1 (kanan) sama-sama 263. */
export const CONTENT_WIDTH = 263;
/** Ukuran A4 dalam pt untuk jsPDF. */
const PDF_W_PT = 595.28;
const PDF_H_PT = 841.89;
const BORDER = '1px solid #000';

export class SlipTooTallError extends Error {
  constructor(height, capacity) {
    super(`Satu baris surat jalan setinggi ${height}px tidak muat dalam satu halaman (maks ${capacity}px).`);
    this.name = 'SlipTooTallError';
    this.height = height;
    this.capacity = capacity;
  }
}

/**
 * Menyusun surat jalan ke baris dan halaman. MURNI.
 *
 * @param {number[]} heights tinggi luar tiap surat jalan (sudah termasuk garis bawah 1 px)
 * @returns {{pages: {rows: {slips: number[], height: number}[]}[]}}
 *
 * Invarian: indeks surat jalan lintas halaman = 0..N-1 sekali saja berurutan;
 * baris tak pernah terbelah antar halaman; jumlah tinggi baris + 1 (garis atas
 * baris pertama) <= pageHeight.
 */
export function layoutSlipRows(heights, { cols = COLS, pageHeight = PAGE_H } = {}) {
  const capacity = pageHeight - 1; // 1 px untuk garis atas baris pertama halaman
  const pages = [];
  let page = null;
  let used = 0;

  for (let start = 0; start < heights.length; start += cols) {
    const slips = [];
    for (let i = start; i < Math.min(start + cols, heights.length); i += 1) slips.push(i);
    const height = Math.max(...slips.map((i) => heights[i]));

    if (height > capacity) throw new SlipTooTallError(height, capacity);

    if (!page || used + height > capacity) {
      page = { rows: [] };
      pages.push(page);
      used = 0;
    }
    page.rows.push({ slips, height });
    used += height;
  }

  return { pages };
}

/**
 * Isi satu surat jalan (tanpa garis tepi — garis digambar oleh grid halaman).
 * Isinya sama dengan surat jalan lama (judul, nomor, Dari | Kepada) tetapi
 * dibentuk ulang untuk sel ±70 mm. Tanpa tinggi tetap dan tanpa clip (D3).
 * Padding 6 px berada DI DALAM garis (FR-005).
 *
 * 068 (research D1/D2): catatan pre-order (`invoice.notes`) dicetak persis di
 * bawah judul "Shipping Info", sebelum nomor, hanya bila tidak kosong — tanpa
 * label, tanpa baris kosong (slip tanpa catatan identik dengan sebelumnya).
 * Teks bebas buatan staf, jadi SELALU lewat escapeHtml (markup ini disuntik via
 * innerHTML untuk pengukuran dan diklon html2canvas). `pre-wrap` menjaga baris
 * baru dari pengguna tanpa menyisipkan <br>. Tidak ada tinggi tetap / clip:
 * catatan panjang menaikkan baris karena tinggi diukur dari markup asli (D3).
 */
export function buildShippingSlipCellHtml(invoice) {
  const store = invoice?.store_identity;
  const customer = invoice?.customer;
  const wrap = 'overflow-wrap:anywhere;word-break:break-word;';
  const muted = `font-size:9px;line-height:12px;color:#444;${wrap}`;
  const label = 'font-size:8px;line-height:10px;font-weight:700;text-transform:uppercase;color:#666;margin-bottom:2px;';
  const notes = String(invoice?.notes ?? '').trim();
  const notesHtml = notes
    ? `<div data-slip-notes style="margin-top:2px;font-size:9px;line-height:12px;color:#222;white-space:pre-wrap;${wrap}">${escapeHtml(notes)}</div>`
    : '';

  return `<div style="padding:6px;font-family:Arial,sans-serif;color:#000;background:#fff;">
  <div style="text-align:center;padding-bottom:4px;margin-bottom:6px;border-bottom:1px dashed #999;">
    <div style="font-size:11px;line-height:14px;font-weight:700;">Shipping Info</div>${notesHtml}
    <div style="font-family:monospace;font-size:11px;line-height:14px;font-weight:700;${wrap}">${escapeHtml(invoice?.preorder_number)}</div>
  </div>
  <div style="display:flex;gap:6px;">
    <div style="flex:1;min-width:0;">
      <div style="${label}">From</div>
      <div style="font-size:10px;line-height:13px;${wrap}">${escapeHtml(store?.name || '')}</div>
      ${store?.address ? `<div style="${muted}">${escapeHtml(store.address)}</div>` : ''}
      ${store?.contact_phone ? `<div style="${muted}">${escapeHtml(store.contact_phone)}</div>` : ''}
      ${store?.contact_person ? `<div style="${muted}">${escapeHtml(store.contact_person)}</div>` : ''}
    </div>
    <div style="flex:1;min-width:0;">
      <div style="${label}">To</div>
      <div style="font-size:10px;line-height:13px;${wrap}">${escapeHtml(customer?.name || '')}</div>
      <div style="${muted}">${escapeHtml(customer?.phone || '')}</div>
      <div style="${muted}">${escapeHtml(customer?.address || '')}</div>
    </div>
  </div>
</div>`;
}

/**
 * HTML satu halaman A4 (794x1123, putih, tanpa padding/margin).
 *
 * @param {{rows: {slips: number[], height: number}[]}} page hasil `layoutSlipRows`
 * @param {string[]} cellHtmls isi tiap surat jalan, diindeks dengan nomor surat jalan
 *
 * Hanya surat jalan yang ADA yang digambar: baris terakhir yang tak penuh tidak
 * membuat kotak kosong (FR-009). Baris pertama halaman bertambah 1 px untuk
 * garis atasnya (kapasitas sudah memperhitungkannya di `layoutSlipRows`).
 */
export function buildSheetPageHtml(page, cellHtmls) {
  const rows = page.rows.map((row, rowIndex) => {
    const first = rowIndex === 0;
    const h = row.height + (first ? 1 : 0);
    const cells = row.slips.map((slipIndex, col) => {
      const borders = [
        `border-right:${BORDER}`,
        `border-bottom:${BORDER}`,
        col === 0 ? `border-left:${BORDER}` : '',
        first ? `border-top:${BORDER}` : '',
      ].filter(Boolean).join(';');
      return `<div data-slip="${slipIndex}" style="box-sizing:border-box;flex:none;width:${COL_WIDTHS[col]}px;height:${h}px;margin:0;${borders};">${cellHtmls[slipIndex]}</div>`;
    }).join('');
    return `<div data-row style="display:flex;width:${PAGE_W}px;height:${h}px;margin:0;padding:0;">${cells}</div>`;
  }).join('');

  return `<div data-sheet-page style="box-sizing:border-box;width:${PAGE_W}px;height:${PAGE_H}px;margin:0;padding:0;background:#fff;">${rows}</div>`;
}

/**
 * Mengukur tinggi luar tiap surat jalan (isi + 1 px garis bawah) pada lebar isi
 * kolom yang sebenarnya. Dipasang di luar layar dengan `position:fixed;left:-9999px`
 * — BUKAN display:none, yang membuat offsetHeight = 0.
 */
export function measureSlipHeights(cellHtmls) {
  const host = document.createElement('div');
  host.style.cssText = `position:fixed;left:-9999px;top:0;width:${CONTENT_WIDTH}px;margin:0;padding:0;`;
  host.innerHTML = cellHtmls
    .map((html) => `<div data-measure style="box-sizing:border-box;width:${CONTENT_WIDTH}px;">${html}</div>`)
    .join('');
  document.body.appendChild(host);
  try {
    return Array.from(host.querySelectorAll('[data-measure]')).map((el) => el.offsetHeight + 1);
  } finally {
    host.remove();
  }
}

/**
 * Membuat SATU PDF A4: ukur -> susun -> rasterisasi tiap halaman -> jsPDF.
 * `measure`/`capture` bisa disuntikkan (jsdom tak punya layout maupun canvas).
 * Halaman diproses berurutan dan node + canvas dilepas sebelum halaman berikut
 * agar memori terbatas (<= 200 surat jalan = puluhan halaman).
 *
 * @returns {Promise<Blob>}
 */
export async function renderShippingSlipSheet(invoices, { measure = measureSlipHeights, capture = captureElementCanvas } = {}) {
  const cellHtmls = invoices.map(buildShippingSlipCellHtml);
  const { pages } = layoutSlipRows(await measure(cellHtmls));

  const { jsPDF } = await import('jspdf');
  const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });

  for (let p = 0; p < pages.length; p += 1) {
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;left:-9999px;top:0;margin:0;padding:0;';
    host.innerHTML = buildSheetPageHtml(pages[p], cellHtmls);
    document.body.appendChild(host);

    let canvas;
    try {
      canvas = await capture(host.firstElementChild);
      if (p > 0) pdf.addPage();
      pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, PDF_W_PT, PDF_H_PT);
    } finally {
      host.remove();
      if (canvas) { canvas.width = 0; canvas.height = 0; } // lepas bitmap sebelum halaman berikut
    }
  }

  return pdf.output('blob');
}
