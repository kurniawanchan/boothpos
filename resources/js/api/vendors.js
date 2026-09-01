import client from './client';

export function listVendors(params = {}) {
  return client.get('/vendors', { params }).then((r) => r.data);
}

export function getVendor(id) {
  return client.get(`/vendors/${id}`).then((r) => r.data);
}

export function createVendor(payload) {
  return client.post('/vendors', payload).then((r) => r.data);
}

export function updateVendor(id, payload) {
  return client.put(`/vendors/${id}`, payload).then((r) => r.data);
}

export function deleteVendor(id) {
  return client.delete(`/vendors/${id}`);
}
