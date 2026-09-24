import client from './client';

export function listCustomers(params = {}) {
  return client.get('/customers', { params }).then((r) => r.data);
}

export function createCustomer(payload) {
  return client.post('/customers', payload).then((r) => r.data);
}

export function updateCustomer(id, payload) {
  return client.put(`/customers/${id}`, payload).then((r) => r.data);
}

export function deleteCustomer(id) {
  return client.delete(`/customers/${id}`);
}

export function customerTransactions(id) {
  return client.get(`/customers/${id}/transactions`).then((r) => r.data);
}

// 020-customer-data-import-export — owner/admin/inventory only server-side.
export function exportCustomers() {
  return client.get('/customers/export', { responseType: 'blob' }).then((r) => r.data);
}

export function downloadCustomerImportTemplate() {
  return client.get('/customers/import/template', { responseType: 'blob' }).then((r) => r.data);
}

export function importCustomers(file, dryRun = false) {
  const form = new FormData();
  form.append('file', file);
  if (dryRun) form.append('dry_run', '1');
  return client.post('/customers/import', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}
