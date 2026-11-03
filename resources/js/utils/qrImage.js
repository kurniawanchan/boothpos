// 090 / US2 — gambar QR meja dirender di KLIEN. Server hanya menerbitkan URL bertanda tangan; pustaka `qrcode` dimuat
// DINAMIS supaya tidak membebani bundel awal (Konstitusi V) dan hanya diunduh saat staf membuka lembar QR.

const OPTIONS = { errorCorrectionLevel: 'M', margin: 1, width: 480 };

// Satu import untuk semua pemanggil: lembar massal membuat puluhan QR sekaligus dan tidak perlu puluhan import paralel.
let library = null;
const loadLibrary = () => (library ??= import('qrcode').then((m) => m.default ?? m));

/** URL → data URL PNG. Data URL tidak pernah lintas-origin, jadi aman untuk html2canvas/jsPDF. */
export async function qrDataUrl(url) {
  const QRCode = await loadLibrary();
  return QRCode.toDataURL(url, OPTIONS);
}

/** Tambah `qr` (data URL) ke tiap item `{ url, ... }`. */
export async function withQrImages(items) {
  return Promise.all(items.map(async (item) => ({ ...item, qr: await qrDataUrl(item.url) })));
}

/** Nama berkas aman: huruf/angka/tanda hubung saja. */
export function qrFilename(parts, ext) {
  const base = parts.filter(Boolean).join('-').normalize('NFKD').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
  return `${base || 'qr-meja'}.${ext}`;
}
