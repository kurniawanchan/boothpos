import client from './client';

export function listPreorders(params = {}) {
  return client.get('/preorders', { params }).then((r) => r.data);
}

export function getPreorder(id) {
  return client.get(`/preorders/${id}`).then((r) => r.data);
}

export function createPreorder(payload) {
  return client.post('/preorders', payload).then((r) => r.data);
}

export function updatePreorderStatus(id, status, cancelReason = null) {
  return client
    .patch(`/preorders/${id}/status`, { status, cancel_reason: cancelReason })
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
