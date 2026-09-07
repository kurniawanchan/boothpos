import client from './client';

// 019-billing-system (T054) — `Invoice` sekarang entitas mandiri top-level
// (`GET/POST/PUT/DELETE /invoices`), bukan lagi anak Company
// (`/companies/{company}/invoices` sudah dihapus di backend — lihat
// InvoiceController::index()'s komentar R2'). Semua fungsi lama
// (`listCompanyInvoices`, `createInvoice(companyId, ...)`) diganti bentuk
// barunya di sini; pemanggil lama (`CompanyInvoicesModal.vue`) dihapus di
// komit yang sama (T058).
export function listInvoices(params = {}) {
  return client.get('/invoices', { params }).then((r) => r.data);
}

export function getInvoice(id) {
  return client.get(`/invoices/${id}`).then((r) => r.data);
}

export function createInvoice(payload) {
  return client.post('/invoices', payload).then((r) => r.data);
}

export function updateInvoice(id, payload) {
  return client.put(`/invoices/${id}`, payload).then((r) => r.data);
}

export function deleteInvoice(id) {
  return client.delete(`/invoices/${id}`);
}

export function markInvoicePaid(id) {
  return client.post(`/invoices/${id}/mark-paid`).then((r) => r.data);
}

export function cancelInvoice(id) {
  return client.post(`/invoices/${id}/cancel`).then((r) => r.data);
}

export function getInvoiceSummary() {
  return client.get('/invoices/summary').then((r) => r.data);
}

// 019-billing-system (T076, Phase 6) — export/import mandiri, mirror
// `resources/js/api/preorders.js`'s exportPreorders/import pattern persis
// (research.md R6'). Rute statis (`/invoices/export`, `/invoices/import/*`)
// terdaftar SEBELUM `{invoice}` di routes/api.php, sama seperti preorders.
export function exportInvoices(params = {}) {
  return client.get('/invoices/export', { params, responseType: 'blob' }).then((r) => r.data);
}

export function getInvoiceImportTemplate() {
  return client.get('/invoices/import/template', { responseType: 'blob' }).then((r) => r.data);
}

export function importInvoices(file, dryRun = false) {
  const form = new FormData();
  form.append('file', file);
  if (dryRun) form.append('dry_run', '1');
  return client.post('/invoices/import', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}
