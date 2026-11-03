// 090 / US2 — lembar QR meja siap cetak. Dua keluaran dari SATU tata letak:
//  - PDF berhalaman A4 (jsPDF, dimuat dinamis) dengan 2 kolom × 3 baris per halaman;
//  - HTML untuk jendela cetak peramban (pengguna bisa memilih "Simpan sebagai PDF" di sana juga).
//
// Mengapa tidak lewat `utils/pdfCapture.js` seperti dokumen lain: pdfCapture memotret SATU elemen DOM menjadi SATU halaman
// sebesar elemen itu — untuk 50–200 QR hasilnya halaman raksasa yang tak bisa dicetak. Lembar ini hanya berisi teks dan
// data URL QR (tidak ada <img> lintas-origin), jadi masalah yang diselesaikan pdfCapture (gambar tertukar/tercemar) tidak ada.

const COLS = 2;
const ROWS = 3;
export const PER_PAGE = COLS * ROWS;

const PAGE = { w: 210, h: 297, margin: 12 }; // A4, mm

/** Posisi tiap sel (mm) untuk `count` kartu; murni, supaya tata letak bisa diuji tanpa jsPDF. */
export function layoutCells(count) {
  const cellW = (PAGE.w - PAGE.margin * 2) / COLS;
  const cellH = (PAGE.h - PAGE.margin * 2) / ROWS;
  return Array.from({ length: count }, (_, i) => {
    const slot = i % PER_PAGE;
    return {
      page: Math.floor(i / PER_PAGE),
      x: PAGE.margin + (slot % COLS) * cellW,
      y: PAGE.margin + Math.floor(slot / COLS) * cellH,
      w: cellW,
      h: cellH,
    };
  });
}

/** @param {{ outlet_name?:string, table_name:string, area?:string, qr:string }[]} items */
export async function downloadQrSheetPdf(items, filename, caption) {
  const { jsPDF } = await import('jspdf');
  const pdf = new jsPDF({ unit: 'mm', format: 'a4' });
  const cells = layoutCells(items.length);

  items.forEach((item, i) => {
    const c = cells[i];
    if (c.page > 0 && i % PER_PAGE === 0) pdf.addPage();

    const qrSize = Math.min(c.w - 16, c.h - 38);
    const cx = c.x + c.w / 2;
    pdf.setDrawColor(200);
    pdf.rect(c.x + 3, c.y + 3, c.w - 6, c.h - 6);
    pdf.setFont('helvetica', 'normal');
    pdf.setFontSize(10);
    pdf.text(String(item.outlet_name ?? ''), cx, c.y + 11, { align: 'center', maxWidth: c.w - 12 });
    pdf.setFont('helvetica', 'bold');
    pdf.setFontSize(22);
    pdf.text(String(item.table_name), cx, c.y + 22, { align: 'center', maxWidth: c.w - 12 });
    pdf.addImage(item.qr, 'PNG', cx - qrSize / 2, c.y + 25, qrSize, qrSize);
    pdf.setFont('helvetica', 'normal');
    pdf.setFontSize(9);
    pdf.text(caption, cx, c.y + c.h - 8, { align: 'center', maxWidth: c.w - 12 });
  });

  pdf.save(filename);
}

const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]);

/** HTML jendela cetak. Semua teks dari pengguna (nama outlet/meja) di-escape. Warna hanya kata kunci CSS (bukan hex). */
export function buildPrintHtml(items, caption, title) {
  const cards = items.map((it) => `
    <section class="card">
      <p class="outlet">${escapeHtml(it.outlet_name)}</p>
      <h1>${escapeHtml(it.table_name)}</h1>
      <img src="${escapeHtml(it.qr)}" alt="">
      <p class="caption">${escapeHtml(caption)}</p>
    </section>`).join('');

  return `<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${escapeHtml(title)}</title>
<style>
  @page { size: A4; margin: 12mm; }
  body { margin: 0; font-family: sans-serif; color: black; }
  .sheet { display: grid; grid-template-columns: repeat(${COLS}, 1fr); gap: 6mm; }
  .card { border: 1px solid gray; text-align: center; padding: 6mm; break-inside: avoid; height: 80mm; box-sizing: border-box; }
  .card h1 { font-size: 22pt; margin: 2mm 0; }
  .card img { width: 52mm; height: 52mm; }
  .outlet { font-size: 10pt; margin: 0; }
  .caption { font-size: 9pt; margin: 1mm 0 0; }
</style></head><body><div class="sheet">${cards}</div></body></html>`;
}

/** Membuka jendela cetak (dipicu klik pengguna, jadi tidak diblokir). false bila jendela diblokir peramban. */
export function printQrSheet(items, caption, title) {
  const win = window.open('', '_blank');
  if (!win) return false;
  win.document.open();
  win.document.write(buildPrintHtml(items, caption, title));
  win.document.close();
  // Cetak SEKALI: lewat `load`, atau lewat cadangan waktu bila `load` sudah lewat sebelum listener terpasang.
  let printed = false;
  const go = () => {
    if (printed) return;
    printed = true;
    try { win.print(); } catch { /* jendela sudah ditutup */ }
  };
  win.addEventListener('load', go);
  setTimeout(go, 600);
  return true;
}
