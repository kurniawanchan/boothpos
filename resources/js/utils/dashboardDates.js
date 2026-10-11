/**
 * 042-dashboard-revamp — format tanggal/jam Dashboard menurut ZONA WAKTU TOKO (pengaturan `store_timezone`),
 * bukan zona waktu peramban: laptop kasir yang zona waktunya salah tidak boleh menggeser "hari ini" atau jam
 * "Diperbarui". Zona yang tak dikenal Intl (nilai rusak) jatuh ke zona peramban alih-alih melempar galat.
 */

function safeFormatter(options, timeZone) {
  try {
    return new Intl.DateTimeFormat('id-ID', { ...options, timeZone });
  } catch {
    return new Intl.DateTimeFormat('id-ID', options);
  }
}

/** Tanggal kalender hari ini (YYYY-MM-DD) di zona toko — dipakai sebelum ringkasan pertama tiba. */
export function todayInZone(timeZone, now = new Date()) {
  try {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
  } catch {
    return now.toISOString().slice(0, 10);
  }
}

/**
 * "Minggu, 4 Okt 2026" dari tanggal bisnis `YYYY-MM-DD`. Tanggal bisnis adalah tanggal KALENDER tanpa zona,
 * jadi diformat dari tengah hari UTC agar tidak pernah bergeser ke hari lain oleh zona mana pun.
 */
export function formatBusinessDate(ymd) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd ?? '');
  if (!m) return '';
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 12));
  return safeFormatter({ weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' }, 'UTC').format(d).replace(/\s+/g, ' ');
}

/** "19.30" — jam 24 jam di zona toko dari stempel waktu ISO (computed_at). Kosong bila tak valid. */
export function formatClock(iso, timeZone) {
  const d = new Date(iso);
  if (!iso || Number.isNaN(d.getTime())) return '';
  return safeFormatter({ hour: '2-digit', minute: '2-digit', hour12: false }, timeZone).format(d).replace(':', '.');
}
