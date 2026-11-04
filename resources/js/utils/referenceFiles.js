/**
 * 055 (US2) — pemilahan berkas yang dipilih untuk referensi transaksi, SEBELUM dikirim.
 *
 * Ini hanya kemudahan agar pengguna langsung tahu berkas mana yang tidak ikut dan kenapa; server tetap
 * memeriksa ulang jenis (dari ISI berkas), ukuran, dan batas gambar yang tersimpan. Aturan:
 *  - jenis lalu ukuran diperiksa per berkas; berkas yang gagal ditolak dengan alasannya,
 *  - berkas yang lolos diterima SESUAI URUTAN PILIHAN sampai slot habis; sisanya ditolak karena batas,
 *  - berkas yang ditolak tidak membatalkan berkas lain yang valid (spec US2 skenario 3).
 */
export const REFERENCE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
export const REFERENCE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

/**
 * @param {File[]} files berkas dari <input type="file" multiple>
 * @param {number} slotsLeft jumlah gambar yang masih boleh ditambahkan
 * @returns {{ accepted: File[], refused: { name: string, reason: 'type'|'size'|'limit' }[] }}
 */
export function triageReferenceFiles(files, slotsLeft) {
  const accepted = [];
  const refused = [];
  let slots = Math.max(0, slotsLeft);

  for (const file of files) {
    if (!REFERENCE_IMAGE_TYPES.includes(file.type)) {
      refused.push({ name: file.name, reason: 'type' });
    } else if (file.size > REFERENCE_IMAGE_MAX_BYTES) {
      refused.push({ name: file.name, reason: 'size' });
    } else if (slots === 0) {
      refused.push({ name: file.name, reason: 'limit' });
    } else {
      accepted.push(file);
      slots -= 1;
    }
  }

  return { accepted, refused };
}
