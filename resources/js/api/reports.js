import client from './client';

export function salesReport(params = {}) {
  return client.get('/reports/sales', { params }).then((r) => r.data);
}

/**
 * Ekspor transaksi halaman Sales. `keys` = kunci baris yang ingin diekspor ("order:12"); server membangun ulang isinya (nominal tak pernah dikirim dari klien).
 * Tanpa `keys` = seluruh transaksi sesuai `params`. Balasan: berkas .xlsx (Blob).
 */
export function exportSalesTransactions(params = {}, keys = null) {
  return client
    .post('/reports/sales/transactions/export', { ...params, keys: keys ?? undefined }, { responseType: 'blob' })
    .then((r) => r.data);
}

// 046 — laporan berskala PERIODE (tanggal bisnis), bukan event. Tanpa `date_from`/`date_to` server memakai
// hari bisnis berjalan. `params` = { date_from, date_to }.
export function profitReport(params = {}) {
  return client.get('/reports/profit', { params }).then((r) => r.data);
}

export function outletSettlements(params = {}) {
  return client.get('/reports/outlet-settlements', { params }).then((r) => r.data);
}

// F11.6 — drill-down transaksi yang menyusun rekap satu outlet. Owner/admin
// saja (backend mengembalikan 403 untuk role lain); pemanggil bertanggung
// jawab menyembunyikan kontrolnya untuk role yang tidak berhak.
export function outletSettlementTransactions(outletId, params = {}) {
  return client.get(`/reports/outlet-settlements/${outletId}/transactions`, { params }).then((r) => r.data);
}

// F9.5 — modal & laba kotor per outlet. Owner/admin saja.
export function outletProfitReport(params = {}) {
  return client.get('/reports/outlet-profit', { params }).then((r) => r.data);
}

// 006-purchase-order-and-ops (US9) — filternya sendiri (vendor_id/status/date_from/date_to).
export function purchasesReport(params = {}) {
  return client.get('/reports/purchases', { params }).then((r) => r.data);
}

// 006-purchase-order-and-ops (US10) — filter opsionalnya outlet_id.
export function stockByOutletReport(params = {}) {
  return client.get('/reports/stock-by-outlet', { params }).then((r) => r.data);
}

// --- 088 Laporan Bahan --------------------------------------------------------------------------------------------
// Catatan 2026-11-02: tab Bahan punya filter Outlet sendiri (`outlet_id[]`, pola 070/071). Header bilah atas
// DILEWATI (`skipOutletHeader`) supaya filter bisa memilih outlet lain; server tetap memotong dengan outlet yang boleh dilihat.
/** GET /reports/materials/position — posisi & risiko per bahan per outlet (`outlet_id[]` = saringan outlet halaman). */
export function getMaterialPosition(params = {}) {
  return client.get('/reports/materials/position', { params, skipOutletHeader: true }).then((r) => r.data);
}

/** GET /reports/materials/usage — pemakaian/terbuang/koreksi/pembelian per periode tanggal bisnis. */
export function getMaterialUsage(params = {}) {
  return client.get('/reports/materials/usage', { params, skipOutletHeader: true }).then((r) => r.data);
}

/** GET /reports/materials/{id}/usage-by-menu — menu yang memakai satu bahan di periode. */
export function getMaterialUsageByMenu(materialId, params = {}) {
  return client.get(`/reports/materials/${materialId}/usage-by-menu`, { params, skipOutletHeader: true }).then((r) => r.data);
}

/**
 * POST /reports/materials/purchase-drafts — draf PO per vendor dari saran terpilih; `key` = Idempotency-Key per niat.
 * Server mengambil outlet TUNGGAL dari header, jadi pemanggil mengirim `outletId` = satu outlet yang disaring di halaman
 * (header bilah atas dilewati; tanpa `outletId` tidak ada header).
 */
export function createMaterialPurchaseDrafts(lines, key, outletId = null) {
  const headers = { 'Idempotency-Key': key, ...(outletId != null ? { 'X-Outlet-Id': String(outletId) } : {}) };
  return client.post('/reports/materials/purchase-drafts', { lines }, { headers, skipOutletHeader: true }).then((r) => r.data);
}

/**
 * report is one of: sales | profit | outlet-settlements | outlet-profit | purchases | stock-by-outlet. Downloaded via
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
