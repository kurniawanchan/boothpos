// Penjaga berkas gambar sisi klien, pola yang sama dengan pemeriksaan .xlsx di MasterDataImportModal —
// gagal cepat sebelum round trip. ASSUMPTION: batas 5 MB dan mime image/*, karena kontrak backend tidak
// menyebut batas di sini. Dipakai bersama oleh gambar produk (drawer) dan gambar per-varian (kartu varian).
export const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/** Mengembalikan kunci i18n galat (`master_data.*`) atau null bila berkas boleh dipakai. */
export function imageFileError(file) {
  if (!file.type.startsWith('image/')) return 'master_data.image_must_be_image_generic';
  if (file.size > MAX_IMAGE_BYTES) return 'master_data.image_max_size_generic';
  return null;
}
