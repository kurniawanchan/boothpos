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
