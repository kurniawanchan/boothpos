// 090 — mengecilkan foto bukti bayar di klien sebelum diunggah. Server hanya menerima JPEG/PNG ≤ 5 MB; foto kamera
// ponsel bisa lebih besar, atau (iOS) berformat HEIC. Hanya bantu UX: server tetap memeriksa tipe dan ukuran dari isi berkas.

export const MAX_UPLOAD_BYTES = 4.5 * 1024 * 1024; // di bawah batas server 5 MB, dengan sedikit ruang
const MAX_SIDE = 1600;

/** Ukuran akhir dengan sisi terpanjang dibatasi; tidak pernah memperbesar. */
export function scaledSize(width, height, maxSide = MAX_SIDE) {
  const longest = Math.max(width, height);
  if (!longest || longest <= maxSide) return { width, height };
  const ratio = maxSide / longest;
  return { width: Math.round(width * ratio), height: Math.round(height * ratio) };
}

const isUploadable = (file) => ['image/jpeg', 'image/png'].includes(file.type);

async function decode(file) {
  if (typeof createImageBitmap === 'function') {
    return createImageBitmap(file);
  }
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      resolve(img);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('decode_failed'));
    };
    img.src = url;
  });
}

function toBlob(canvas, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}

/**
 * Mengembalikan File yang aman diunggah. Berkas JPEG/PNG yang sudah ≤ batas dikembalikan APA ADANYA (tanpa kompres
 * ulang, kualitas tetap). Selain itu dikonversi ke JPEG lewat kanvas dengan sisi terpanjang ≤ 1600 px dan kualitas
 * diturunkan bertahap sampai muat. Gagal diproses → melempar Error('unsupported').
 */
export async function prepareProofImage(file) {
  if (isUploadable(file) && file.size <= MAX_UPLOAD_BYTES) {
    return file;
  }

  try {
    const bitmap = await decode(file);
    const { width, height } = scaledSize(bitmap.width, bitmap.height);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('no_canvas');
    ctx.drawImage(bitmap, 0, 0, width, height);
    bitmap.close?.();

    for (const quality of [0.85, 0.7, 0.55, 0.4]) {
      const blob = await toBlob(canvas, quality);
      if (blob && blob.size <= MAX_UPLOAD_BYTES) {
        const name = (file.name || 'bukti').replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], name, { type: 'image/jpeg' });
      }
    }
  } catch {
    // jatuh ke galat di bawah
  }
  throw new Error('unsupported');
}
