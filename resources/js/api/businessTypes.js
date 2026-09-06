import client from './client';

export function listBusinessTypes(params = {}) {
  return client.get('/business-types', { params }).then((r) => r.data);
}

export function createBusinessType(payload) {
  return client.post('/business-types', payload).then((r) => r.data);
}

export function updateBusinessType(id, payload) {
  return client.put(`/business-types/${id}`, payload).then((r) => r.data);
}

export function deleteBusinessType(id) {
  return client.delete(`/business-types/${id}`);
}
