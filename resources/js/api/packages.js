import client from './client';

export function listPackages(params = {}) {
  return client.get('/packages', { params }).then((r) => r.data);
}

export function createPackage(payload) {
  return client.post('/packages', payload).then((r) => r.data);
}

export function updatePackage(id, payload) {
  return client.put(`/packages/${id}`, payload).then((r) => r.data);
}

export function deletePackage(id) {
  return client.delete(`/packages/${id}`);
}
