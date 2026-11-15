/**
 * Perender halaman A4 bersama (069-courier-shipping-label-a4, research D7).
 *
 * Diekstrak dari surat jalan massal 067 (`renderShippingSlipSheet`) karena label
 * kurir 069 butuh langkah yang persis sama: satu rasterisasi per HALAMAN, lalu
 * satu jsPDF A4 potret. Satu implementasi untuk dua pemanggil (Konstitusi I);
 * markup sel dan tata letak tetap milik masing-masing dokumen.
 *
 * Halaman diproses berurutan; node dan bitmap canvas dilepas sebelum halaman
 * berikutnya agar memori tetap terbatas (≤ 200 dokumen = puluhan halaman).
 * Node dipasang di luar layar dengan `position:fixed;left:-9999px` — BUKAN
 * display:none, yang membuat html2canvas menggambar kosong.
 */
import { captureElementCanvas } from './pdfCapture';

/** Ukuran A4 dalam pt untuk jsPDF. */
export const PDF_W_PT = 595.28;
export const PDF_H_PT = 841.89;

/**
 * @param {string[]} pageHtmls HTML satu halaman A4 (elemen pertama = halaman yang direkam)
 * @param {{capture?: (el: HTMLElement) => Promise<HTMLCanvasElement>}} [opts] `capture` bisa disuntikkan (jsdom tak punya canvas)
 * @returns {Promise<Blob>}
 */
export async function renderA4Pages(pageHtmls, { capture = captureElementCanvas } = {}) {
  const { jsPDF } = await import('jspdf');
  const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });

  for (let p = 0; p < pageHtmls.length; p += 1) {
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;left:-9999px;top:0;margin:0;padding:0;';
    host.innerHTML = pageHtmls[p];
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
