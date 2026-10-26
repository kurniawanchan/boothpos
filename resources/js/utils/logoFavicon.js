// 076-settlement-tab-session-branding (US4) — logo toko → ikon tab.
//
// Logo bisa persegi panjang; browser menyesakkan ikon tab ke kotak kecil sehingga
// logo lebar akan tergencet. Di sini logo digambar "contain" ke kanvas persegi
// 64×64 dan dipakai sebagai data URL PNG.
//
// Hasil: data URL bila bisa digambar; URL asli bila gambar berhasil dimuat
// tetapi kanvas "tainted" (URL absolut lintas-origin ketika halaman dibuka lewat
// host/IP lain — <link rel=icon> tidak butuh CORS jadi URL asli tetap bisa
// dipakai); null bila gambar GAGAL dimuat (pemanggil jatuh ke ikon bawaan —
// jangan pernah menampilkan ikon rusak).
export function logoToFavicon(url, size = 64) {
  return new Promise((resolve) => {
    if (!url) {
      resolve(null);
      return;
    }

    const img = new Image();
    img.onload = () => {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('no 2d context');

        const scale = Math.min(size / img.width, size / img.height);
        const w = img.width * scale;
        const h = img.height * scale;
        ctx.drawImage(img, (size - w) / 2, (size - h) / 2, w, h);
        resolve(canvas.toDataURL('image/png'));
      } catch {
        resolve(url);
      }
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
}
