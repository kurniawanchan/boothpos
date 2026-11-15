/**
 * Label kurir A4 — 4 label per halaman (feature 069-courier-shipping-label-a4).
 *
 * Dokumen BARU di samping surat jalan (067); surat jalan lama tidak berubah.
 * Isinya dari shipment TERSIMPAN (`POST /preorders/shipping-labels`), dipakai
 * unduhan tunggal (drawer detail) dan massal (pilihan daftar).
 *
 * Keputusan desain (specs/069-courier-shipping-label-a4/research.md):
 *  - D5  grid tetap 2×2 bilangan bulat pada halaman 794x1123 px: kolom 397+396,
 *        baris 562+561. Kolom/baris pertama juga memiliki garis kiri/atas, jadi
 *        tetangga berbagi SATU garis (pola 067 D2) dan setiap sel punya kotak isi
 *        395x560 px (≈ A6). Garis potong selalu di tempat yang sama di tiap lembar.
 *  - D6  tinggi isi DIUKUR dari markup asli; label lebih tinggi dari selnya =
 *        LabelTooTallError (unduhan gagal dengan pesan), TIDAK PERNAH dipotong
 *        (tanpa overflow:hidden / line-clamp — jebakan html2canvas 049).
 *  - D7  rasterisasi per halaman lewat utils/a4PdfPages (dipakai bersama 067).
 *  - D8  urutan baca kurir: kurir + resi di atas, PENERIMA dominan, pengirim lebih
 *        kecil, nomor pesanan di kaki. Keterangan berbahasa Inggris tetap, sama
 *        dengan surat jalan 067. Kotak resi tetap tampil KOSONG bila belum ada
 *        resi (bisa ditulis tangan), tidak pernah berisi teks pengganti.
 *
 * Markup PDF memakai gaya inline hitam-di-atas-putih (dokumen cetak, bukan UI),
 * dan SEMUA nilai buatan pengguna lewat escapeHtml karena markup ini disuntik
 * via innerHTML untuk pengukuran dan diklon html2canvas.
 */
import { escapeHtml } from './invoiceDocument';
import { renderA4Pages } from './a4PdfPages';

/** A4 pada 96 dpi (CSS px). */
export const PAGE_W = 794;
export const PAGE_H = 1123;
export const PER_PAGE = 4;
/** 397+396 = 793 (sisa 1 px di tepi kanan, tak terlihat); 562+561 = 1123. Bilangan bulat -> garis tajam. */
export const COL_WIDTHS = [397, 396];
export const ROW_HEIGHTS = [562, 561];
/** Kotak isi tiap sel di dalam garis: 397-2 = 396-1 = 395; 562-2 = 561-1 = 560. */
export const CONTENT_W = 395;
export const CONTENT_H = 560;
const BORDER = '1px solid #000';

export class LabelTooTallError extends Error {
  constructor(index, height, capacity) {
    super(`Label ke-${index + 1} setinggi ${height}px tidak muat dalam satu sel (maks ${capacity}px).`);
    this.name = 'LabelTooTallError';
    this.index = index;
    this.height = height;
    this.capacity = capacity;
  }
}

/** Indeks label per halaman, berurutan, 4 per halaman. MURNI. */
export function labelPages(count) {
  const pages = [];
  for (let start = 0; start < count; start += PER_PAGE) {
    pages.push(Array.from({ length: Math.min(PER_PAGE, count - start) }, (_, i) => start + i));
  }
  return pages;
}

const text = (v) => escapeHtml(v == null ? '' : String(v));

/**
 * Isi satu label (tanpa garis tepi — garis digambar oleh grid halaman).
 * Tanpa tinggi tetap dan tanpa clip; `pre-wrap` menjaga baris baru dari staf.
 */
export function buildCourierLabelCellHtml(label, store) {
  const wrap = 'overflow-wrap:anywhere;word-break:break-word;white-space:pre-wrap;';
  const caption = 'font-size:9px;line-height:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#555;margin-bottom:3px;';
  const small = `font-size:11px;line-height:14px;color:#222;${wrap}`;
  const s = store || {};

  return `<div style="box-sizing:border-box;padding:12px;font-family:Arial,sans-serif;color:#000;background:#fff;">
  <div style="display:flex;gap:10px;padding-bottom:10px;border-bottom:2px solid #000;">
    <div style="flex:0 0 140px;min-width:0;">
      <div style="${caption}">Courier</div>
      <div style="font-size:20px;line-height:24px;font-weight:700;${wrap}">${text(label?.courier_name)}</div>
    </div>
    <div style="flex:1;min-width:0;">
      <div style="${caption}">Tracking No.</div>
      <div data-label-tracking style="box-sizing:border-box;min-height:32px;border:1.5px solid #000;padding:4px 6px;font-family:monospace;font-size:16px;line-height:22px;font-weight:700;${wrap}">${text(label?.tracking_number)}</div>
    </div>
  </div>
  <div style="padding:12px 0;border-bottom:1px dashed #777;">
    <div style="${caption}">To</div>
    <div style="font-size:20px;line-height:25px;font-weight:700;${wrap}">${text(label?.recipient_name)}</div>
    <div style="margin-top:4px;font-size:15px;line-height:20px;font-weight:700;${wrap}">${text(label?.recipient_phone)}</div>
    <div style="margin-top:4px;font-size:15px;line-height:20px;${wrap}">${text(label?.address_line)}</div>
  </div>
  <div style="padding:10px 0;border-bottom:1px dashed #777;">
    <div style="${caption}">From</div>
    <div style="font-size:12px;line-height:15px;font-weight:700;${wrap}">${text(s.name)}</div>
    ${s.address ? `<div style="${small}">${text(s.address)}</div>` : ''}
    ${s.contact_phone ? `<div style="${small}">${text(s.contact_phone)}</div>` : ''}
    ${s.contact_person ? `<div style="${small}">${text(s.contact_person)}</div>` : ''}
  </div>
  <div style="padding-top:8px;font-size:11px;line-height:14px;">
    <span style="${caption}display:inline;">Order</span>
    <span style="font-family:monospace;font-size:13px;font-weight:700;${wrap}">${text(label?.preorder_number)}</span>
  </div>
</div>`;
}

/**
 * HTML satu halaman A4. `indices` = nomor label di halaman ini (≤ 4), sel ke-k
 * diisi baris-demi-baris (kiri->kanan, atas->bawah). Hanya label yang ADA yang
 * digambar; sel kosong di halaman terakhir tidak diberi kotak.
 */
export function buildLabelPageHtml(indices, cellHtmls) {
  const cells = indices.map((labelIndex, k) => {
    const col = k % 2;
    const row = Math.floor(k / 2);
    const borders = [
      `border-right:${BORDER}`,
      `border-bottom:${BORDER}`,
      col === 0 ? `border-left:${BORDER}` : '',
      row === 0 ? `border-top:${BORDER}` : '',
    ].filter(Boolean).join(';');
    const left = col === 0 ? 0 : COL_WIDTHS[0];
    const top = row === 0 ? 0 : ROW_HEIGHTS[0];
    return `<div data-label="${labelIndex}" style="position:absolute;box-sizing:border-box;left:${left}px;top:${top}px;width:${COL_WIDTHS[col]}px;height:${ROW_HEIGHTS[row]}px;margin:0;${borders};">${cellHtmls[labelIndex]}</div>`;
  }).join('');

  return `<div data-label-page style="position:relative;box-sizing:border-box;width:${PAGE_W}px;height:${PAGE_H}px;margin:0;padding:0;background:#fff;">${cells}</div>`;
}

/**
 * Tinggi isi tiap label pada lebar isi sel yang sebenarnya. Dipasang di luar
 * layar dengan `position:fixed;left:-9999px` — BUKAN display:none (offsetHeight 0).
 */
export function measureLabelHeights(cellHtmls) {
  const host = document.createElement('div');
  host.style.cssText = `position:fixed;left:-9999px;top:0;width:${CONTENT_W}px;margin:0;padding:0;`;
  host.innerHTML = cellHtmls
    .map((html) => `<div data-measure style="box-sizing:border-box;width:${CONTENT_W}px;">${html}</div>`)
    .join('');
  document.body.appendChild(host);
  try {
    return Array.from(host.querySelectorAll('[data-measure]')).map((el) => el.offsetHeight);
  } finally {
    host.remove();
  }
}

/**
 * SATU PDF A4: ukur -> tolak yang tak muat -> 4 label per halaman -> rasterisasi.
 * `measure`/`capture` bisa disuntikkan (jsdom tak punya layout maupun canvas).
 *
 * @returns {Promise<Blob>}
 */
export async function renderCourierLabels(labels, store, { measure = measureLabelHeights, capture } = {}) {
  const cellHtmls = labels.map((l) => buildCourierLabelCellHtml(l, store));
  const heights = await measure(cellHtmls);

  const tooTall = heights.findIndex((h) => h > CONTENT_H);
  if (tooTall !== -1) throw new LabelTooTallError(tooTall, heights[tooTall], CONTENT_H);

  const pageHtmls = labelPages(labels.length).map((indices) => buildLabelPageHtml(indices, cellHtmls));
  return renderA4Pages(pageHtmls, capture ? { capture } : {});
}
