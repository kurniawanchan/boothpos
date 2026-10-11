/**
 * 041-back-office-ui-redesign — format tampilan yang dipakai kit halaman (kartu KPI, tabel, header).
 * Uang dan tanggal biasa tetap berasal dari money.js/date.js (satu implementasi per kebutuhan); file ini
 * hanya menambah bentuk yang baru muncul di desain: "Rp 18.420.500", "Minggu, 4 Okt 2026", "19.30" dan
 * rentang halaman "Menampilkan X–Y dari Z".
 */
import { formatIDR } from './money';
import { formatDate, formatDateTime } from './date';

export { formatIDR, formatDate, formatDateTime };

const longDateFmt = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
const timeFmt = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false });
const countFmt = new Intl.NumberFormat('id-ID');

/** "Minggu, 4 Okt 2026" — tanggal panjang untuk judul halaman. */
export function formatDateLong(value) {
  const d = new Date(value);
  if (!value || Number.isNaN(d.getTime())) return '—';
  return longDateFmt.format(d).replace(/\s+/g, ' ');
}

/** "19.30" — jam 24 jam dengan titik, seperti desain. */
export function formatTimeShort(value) {
  const d = new Date(value);
  if (!value || Number.isNaN(d.getTime())) return '—';
  return timeFmt.format(d).replace(':', '.');
}

/** "1.284" — bilangan bulat dengan pemisah ribuan titik. */
export function formatCount(value) {
  const n = Number(value);
  return Number.isFinite(n) ? countFmt.format(n) : '—';
}

/**
 * Rentang baris pada halaman untuk teks "Menampilkan {from}–{to} dari {total}". Aman untuk meta kosong
 * atau total 0 (hasilnya from = to = 0), supaya teks tidak pernah menampilkan "1–0".
 *
 * @param {{current_page?: number, per_page?: number, total?: number}} meta amplop paginasi `{data, meta}`
 */
export function pageRange(meta) {
  const total = Math.max(0, Number(meta?.total) || 0);
  if (total === 0) return { from: 0, to: 0, total: 0 };
  const per = Math.max(1, Number(meta?.per_page) || 1);
  const page = Math.max(1, Number(meta?.current_page) || 1);
  const from = (page - 1) * per + 1;
  return { from: Math.min(from, total), to: Math.min(page * per, total), total };
}
