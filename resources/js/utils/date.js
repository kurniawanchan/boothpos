const dateFmt = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });
const dateTimeFmt = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

export function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return dateFmt.format(d);
}

export function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return dateTimeFmt.format(d);
}

/** "31 Okt 2026 – 1 Nov 2026" for an Event marked available_on: 'both'. */
export function formatDateRange(start, end) {
  if (!start || !end) return '—';
  return `${formatDate(start)} – ${formatDate(end)}`;
}

/** yyyy-mm-dd for <input type="date"> binding. */
export function toDateInputValue(value) {
  if (!value) return '';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return d.toISOString().slice(0, 10);
}

/**
 * yyyy-mm-dd menurut hari LOKAL pengguna (bukan UTC, beda dari toDateInputValue).
 * Dipakai untuk membandingkan waktu transaksi dengan kolom tanggal filter: transaksi
 * pukul 00.30 WIB harus jatuh ke hari itu, bukan ke hari sebelumnya di UTC.
 */
export function toLocalDateKey(value) {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
