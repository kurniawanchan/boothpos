import client from './client';

export function salesReport(params = {}) {
  return client.get('/reports/sales', { params }).then((r) => r.data);
}

export function profitReport(eventId) {
  return client.get('/reports/profit', { params: { event_id: eventId } }).then((r) => r.data);
}

export function artistSettlements(eventId) {
  return client
    .get('/reports/artist-settlements', { params: { event_id: eventId } })
    .then((r) => r.data);
}

// F11.6 — drill-down transaksi yang menyusun rekap satu artist. Owner/admin
// saja (backend mengembalikan 403 untuk role lain); pemanggil bertanggung
// jawab menyembunyikan kontrolnya untuk role yang tidak berhak.
export function artistSettlementTransactions(artistId, eventId) {
  return client
    .get(`/reports/artist-settlements/${artistId}/transactions`, { params: { event_id: eventId } })
    .then((r) => r.data);
}

// F9.5 — modal & laba kotor per artist (terpisah dari profitReport() di
// atas, yang berskala event). Owner/admin saja.
export function artistProfitReport(eventId) {
  return client.get('/reports/artist-profit', { params: { event_id: eventId } }).then((r) => r.data);
}

// 006-purchase-order-and-ops (US9) — tidak diskop event seperti tab lain di
// halaman ini, jadi filternya sendiri (vendor_id/status/date_from/date_to).
export function purchasesReport(params = {}) {
  return client.get('/reports/purchases', { params }).then((r) => r.data);
}

// 006-purchase-order-and-ops (US10) — juga tidak diskop event; filter
// opsionalnya artist_id.
export function stockByArtistReport(params = {}) {
  return client.get('/reports/stock-by-artist', { params }).then((r) => r.data);
}

export function recordSettlementPayment(settlementId, payload) {
  return client
    .post(`/reports/artist-settlements/${settlementId}/payment`, payload)
    .then((r) => r.data);
}

/**
 * report is one of: sales | profit | artist-settlements. Downloaded via
 * axios (not a plain <a href>) because the export endpoint requires the
 * same Bearer token as every other request — a bare link would hit it
 * unauthenticated and get a 401.
 */
export async function exportReport(report, params = {}) {
  const response = await client.get(`/reports/${report}/export`, { params, responseType: 'blob' });
  const url = URL.createObjectURL(response.data);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${report}.xlsx`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
