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
