/** Harus sama dengan App\Support\AppName::DEFAULT di backend (server tetap sumber kebenaran). */
export const DEFAULT_APP_NAME = 'BoothPOS';

/**
 * Nama aplikasi yang aman dipakai untuk ditampilkan: nilai dari payload/pengaturan
 * bisa kosong atau hanya spasi (= "pakai default"), jadi dinormalkan di satu tempat
 * supaya store, komponen dokumen, dan pembuat HTML unduhan massal tidak masing-masing
 * menulis ulang aturan dan default-nya.
 */
export function resolveAppName(value) {
  const name = String(value ?? '').trim();

  return name || DEFAULT_APP_NAME;
}
