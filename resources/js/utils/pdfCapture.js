// Shared by every "download this document as PDF/image" button (invoice,
// payment invoice, sale receipt, purchase order, generic invoice) — all of
// them rasterize a live, visible DOM element client-side (html2canvas ->
// canvas -> PNG/PDF); there is no server-side PDF generation anywhere in
// this codebase.
//
// BUG YANG DITEMUKAN & DIPERBAIKI — every one of these documents embeds at
// least one <img> (store logo, and/or a payment channel QR code) whose src
// is an ABSOLUTE URL baked from the server's own APP_URL/storage config at
// write time. This app is reached over `localhost` in the common case, but
// is also documented as reachable via a different host/IP entirely (a
// tablet or a second device on the same LAN, per features 008/016's
// deployment paths) — when the page itself is loaded from one of those
// other hosts, the stored image URL is CROSS-ORIGIN relative to the page,
// even though it points at the very same server. html2canvas still draws
// the image fine (it has no way to know), but the resulting <canvas> is
// then "tainted": canvas.toDataURL()/toBlob() throws/silently fails the
// instant you try to read pixel data back out — which is exactly the point
// where every one of these download handlers calls it. The failure is
// silent from the user's side (a bare try/catch just shows a generic
// toast) and depends entirely on which host the browser happened to load
// the page from, which is why it doesn't reproduce on every machine.
//
// Fix: fetch every <img> inside the element as a blob and swap it to a
// `data:` URI in html2canvas's own cloned copy of the DOM (the `onclone`
// hook) — a data URI is never cross-origin, so the canvas it produces is
// never tainted, regardless of which host served the original image. The
// LIVE, visible document is never touched — only the offscreen clone
// html2canvas rasterizes.
//
// BUG YANG DITEMUKAN & DIPERBAIKI (029-fix-bulk-invoice-logo) — gambar yang
// sudah di-fetch dulu disimpan dalam array lalu dicocokkan ke klon lewat
// INDEKS: `clonedDoc.querySelectorAll('img')[i]`. Tapi argumen pertama
// `onclone` adalah klon SELURUH halaman (html2canvas 1.4.1:
// `onclone(documentClone, referenceElement)`), bukan hanya elemen yang
// difoto. Satu saja gambar lain di halaman yang muncul SEBELUM dokumen
// (avatar di header, thumbnail produk, gambar panel yang terbuka) menggeser
// semua indeks: slot logo menerima data gambar QR, QR dibiarkan, dan PDF
// faktur massal menampilkan QRIS di tempat logo toko. Unduh massal paling
// kena karena container dokumennya ditempel di AKHIR <body>.
//
// Sekarang pencocokan memakai IDENTITAS gambar — atribut `src`-nya sendiri —
// bukan posisi, dan hanya mencari di dalam klon elemen acuan bila html2canvas
// menyediakannya. Sebuah gambar hanya bisa menerima byte miliknya sendiri,
// berapa pun gambar lain di halaman; gambar yang gagal di-fetch dibiarkan
// dengan src aslinya (tak pernah diganti gambar lain). URL yang sama
// di-fetch sekali saja. JANGAN kembali ke pencocokan berdasarkan indeks.
async function imageToDataUrl(src) {
  const res = await fetch(src);
  const blob = await res.blob();
  return await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onloadend = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

/**
 * Rasterizes `el` (a live DOM element, still attached and visible — the
 * exact node the user is looking at) into a <canvas>, immune to the
 * cross-origin-image taint described above.
 */
export async function captureElementCanvas(el) {
  const { default: html2canvas } = await import('html2canvas');

  // Kunci = atribut src MENTAH (html2canvas menyalin atribut apa adanya ke klon,
  // jadi kunci yang sama ditemukan di sisi klon); fetch memakai URL hasil resolusi.
  const imgEls = Array.from(el.querySelectorAll('img')).filter((img) => img.getAttribute('src'));
  const bySrc = new Map();
  await Promise.all(
    [...new Map(imgEls.map((img) => [img.getAttribute('src'), img.src])).entries()].map(async ([rawSrc, resolvedSrc]) => {
      const dataUrl = await imageToDataUrl(resolvedSrc).catch(() => null);
      if (dataUrl) bySrc.set(rawSrc, dataUrl);
    })
  );

  return html2canvas(el, {
    backgroundColor: '#ffffff',
    scale: 2,
    useCORS: true,
    onclone: (clonedDoc, clonedRoot) => {
      (clonedRoot ?? clonedDoc).querySelectorAll('img').forEach((img) => {
        const dataUrl = bySrc.get(img.getAttribute('src'));
        if (dataUrl) img.src = dataUrl;
      });
    },
  });
}

/** Rasterizes `el` and triggers a single-page PDF download as `filename`. */
export async function downloadElementAsPdf(el, filename) {
  const canvas = await captureElementCanvas(el);
  const { jsPDF } = await import('jspdf');
  const imgData = canvas.toDataURL('image/png');
  const widthPt = (canvas.width * 72) / 96;
  const heightPt = (canvas.height * 72) / 96;
  const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
  pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
  pdf.save(filename);
}

/** Rasterizes `el` and triggers a PNG image download as `filename`. */
export async function downloadElementAsPng(el, filename) {
  const canvas = await captureElementCanvas(el);
  const url = canvas.toDataURL('image/png');
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
}
