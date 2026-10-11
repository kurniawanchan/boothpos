/**
 * 043 — gaya sel tabel yang dipakai bersama laci "Varian & harga" dan laci "Resep".
 *
 * Mengapa file kelas, bukan komponen: kedua laci memakai elemen asli (<input>, <select>) langsung di dalam <td>
 * supaya `v-model`, atribut `aria-*` dan pengujian tetap sederhana; yang perlu dibagi hanya tampilannya. Input di
 * dalam sel sengaja datar (tanpa bingkai sendiri) sampai difokus — bingkai tabel yang menjadi "kotak"-nya, persis
 * rancangan Back Office. Hanya token Tailwind (tanpa hex) dan tanpa `min`/`max` pada angka (lihat catatan di laci).
 */
const BASE =
  'block h-11 w-full border-0 bg-transparent px-3 text-[13.5px] text-ink outline-none transition-colors placeholder:text-muted-3 focus:bg-white focus:ring-2 focus:ring-inset';

const NUMBER = ' text-right tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none';

/** Kelas untuk <input>/<select> di dalam sel. `error` mengganti cincin fokus jadi merah dan menandai sel. */
export function cellInputClass({ number = false, error = false } = {}) {
  return `${BASE}${number ? NUMBER : ''} ${error ? 'ring-2 ring-inset ring-danger-border focus:ring-danger-border' : 'focus:ring-brand'}`;
}

/** Kelas judul kolom: kecil, huruf besar, redup (pola yang sama di kedua tabel). */
export const CELL_HEAD = 'px-3 py-2.5 text-[11.5px] font-bold uppercase tracking-wide text-muted';

/** Pesan galat per sel (di bawah input, di dalam sel yang sama). */
export const CELL_ERROR = 'px-3 pb-2 text-[12px] font-medium text-danger-text';

/** Tautan aksi ungu di bawah tabel ("+ Tambah ..."). */
export const TABLE_LINK = 'text-[13px] font-semibold text-brand hover:text-brand-hover focus-visible:outline-2 focus-visible:outline-brand';

/** Tombol "×" kecil redup di ujung baris. */
export const ROW_REMOVE = 'inline-flex h-8 w-8 items-center justify-center text-muted-3 transition-colors hover:text-danger-text focus-visible:outline-2 focus-visible:outline-brand';
