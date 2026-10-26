// Money helpers. The API always speaks decimal strings ("150000.00") to
// avoid float rounding drift (see openapi-pos-mvp.yaml `Money` schema) — we
// mirror that discipline at the edges and only use JS numbers internally
// for arithmetic that we then re-serialize before sending.

const idr = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

/** Format a Money string/number as "Rp 150.000" for display. */
export function formatIDR(value) {
  const n = typeof value === 'string' ? parseFloat(value) : value;
  if (!Number.isFinite(n)) return 'Rp 0';
  return `Rp ${idr.format(Math.round(n))}`;
}

/** Serialize a JS number to the API's two-decimal Money string shape. */
export function toMoneyString(value) {
  const n = typeof value === 'string' ? parseFloat(value) : value;
  if (!Number.isFinite(n)) return '0.00';
  return n.toFixed(2);
}

/** Parse a Money string (or already-a-number) into a JS number. */
export function parseMoney(value) {
  const n = typeof value === 'string' ? parseFloat(value) : value;
  return Number.isFinite(n) ? n : 0;
}

const idrExact = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * 076 — untuk settlement: sen TIDAK dibulatkan (satu-satunya layar di mana
 * baris harus bisa dijumlahkan persis dengan mata). Rupiah bulat tampil
 * tanpa desimal; ada sen → dua desimal. Negatif: "-Rp 5.000".
 */
export function formatIDRExact(value) {
  const n = typeof value === 'string' ? parseFloat(value) : value;
  if (!Number.isFinite(n)) return 'Rp 0';
  const abs = Math.abs(n);
  const hasCents = Math.round(abs * 100) % 100 !== 0;
  const body = hasCents ? idrExact.format(abs) : idr.format(Math.round(abs));
  return `${n < 0 ? '-' : ''}Rp ${body}`;
}
