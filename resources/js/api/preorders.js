import client from './client';

export function listPreorders(params = {}) {
  return client.get('/preorders', { params }).then((r) => r.data);
}

// 013-preorder-list-filters-receipt (US5, T025) — same filter set as
// listPreorders() above, applied via the shared applyFilters() helper
// server-side (contracts/api-deltas.md), so the two never disagree.
export function getPreorderSummary(params = {}) {
  return client.get('/preorders/summary', { params }).then((r) => r.data);
}

export function getPreorder(id) {
  return client.get(`/preorders/${id}`).then((r) => r.data);
}

export function createPreorder(payload) {
  return client.post('/preorders', payload).then((r) => r.data);
}

// 022-preorder-invoice-crud-overhaul (US1)
export function updatePreorder(id, payload) {
  return client.patch(`/preorders/${id}`, payload).then((r) => r.data);
}

export function deletePreorder(id) {
  return client.delete(`/preorders/${id}`).then((r) => r.data);
}

export function updatePreorderStatus(id, status, cancelReason = null) {
  return client
    .patch(`/preorders/${id}/status`, { status, cancel_reason: cancelReason })
    .then((r) => r.data);
}

// Penanda manual invoice-terkirim / pengiriman-berjalan — terpisah dari
// updatePreorderStatus() (tidak menyentuh stok/pembayaran).
export function updatePreorderDispatchStatus(id, dispatchStatus) {
  return client
    .patch(`/preorders/${id}/dispatch-status`, { dispatch_status: dispatchStatus })
    .then((r) => r.data);
}

export function createPreorderPayment(id, payload) {
  return client.post(`/preorders/${id}/payments`, payload).then((r) => r.data);
}

// 007-preorder-import-export-notify (US2)
export function getPreorderInvoice(id) {
  return client.get(`/preorders/${id}/invoice`).then((r) => r.data);
}

// 007-preorder-import-export-notify (US3) — owner/admin only server-side.
export function exportPreorders(params = {}) {
  return client.get('/preorders/export', { params, responseType: 'blob' }).then((r) => r.data);
}

export function downloadPreorderImportTemplate() {
  return client.get('/preorders/import/template', { responseType: 'blob' }).then((r) => r.data);
}

export function importPreorders(file, dryRun = false) {
  const form = new FormData();
  form.append('file', file);
  if (dryRun) form.append('dry_run', '1');
  return client.post('/preorders/import', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}

// 007-preorder-import-export-notify (US4) — owner/admin only server-side.
export function resendPreorderNotification(id) {
  return client.post(`/preorders/${id}/notifications/resend`).then((r) => r.data);
}

// 022-preorder-invoice-crud-overhaul (US5)
export function bulkPreorderInvoices(preorderIds, document = 'invoice') {
  return client.post('/preorders/bulk-invoices', { preorder_ids: preorderIds, document }).then((r) => r.data);
}

export function bulkEmailPreorderInvoices(preorderIds, document = 'invoice') {
  return client.post('/preorders/bulk-email', { preorder_ids: preorderIds, document }).then((r) => r.data);
}

// 027-preorder-duplicate-split — selalu mengembalikan laporan per-pre-order
// ({ data: [{ source_id, source_number, status, preorder|error }] }); satu
// pre-order yang gagal tidak menggagalkan yang lain.
export function duplicatePreorders(preorderIds) {
  return client.post('/preorders/duplicate', { preorder_ids: preorderIds }).then((r) => r.data);
}

// payload: { mode: 'items', items: [{ item_id, qty }] } atau { mode: 'by_seller' }
export function splitPreorder(id, payload) {
  return client.post(`/preorders/${id}/split`, payload).then((r) => r.data);
}

// Hapus satu pembayaran (+ bukti bayarnya) lalu hitung ulang status — owner/admin saja
// (server menegakkan; 409 untuk pre-order yang sudah diserahkan/dibatalkan).
export function deletePreorderPayment(preorderId, paymentId) {
  return client.delete(`/preorders/${preorderId}/payments/${paymentId}`).then((r) => r.data);
}
