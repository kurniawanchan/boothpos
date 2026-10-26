// 076 (US3) — cetak SATU elemen (dokumen settlement) tanpa mencetak seluruh
// halaman aplikasi: salin elemen + stylesheet halaman ke iframe tersembunyi
// lalu panggil print() di sana. Gambar di dokumen sudah berupa data:/blob:
// URL sehingga ikut tercetak. Dokumen lain di aplikasi hanya diunduh; ini
// jalur "cetak" langsung yang diminta untuk bukti settlement.
function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function printElement(el, title = '') {
  if (!el) return false;

  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
  document.body.appendChild(frame);

  const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"], style')).map((n) => n.outerHTML).join('');
  const doc = frame.contentDocument;
  doc.open();
  doc.write(
    `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(title)}</title>${styles}` +
      '<style>@page{size:A4;margin:12mm}html,body{margin:0;background:#fff}</style></head>' +
      `<body>${el.outerHTML}</body></html>`,
  );
  doc.close();

  // Beri waktu stylesheet/gambar dimuat sebelum dialog cetak dibuka.
  setTimeout(() => {
    frame.contentWindow?.focus();
    frame.contentWindow?.print();
    setTimeout(() => frame.remove(), 1000);
  }, 350);

  return true;
}
