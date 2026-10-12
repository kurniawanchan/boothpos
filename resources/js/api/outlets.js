import client from './client';

export function listOutlets(params = {}) {
  return client.get('/outlets', { params }).then((r) => r.data);
}

export function getOutlet(id) {
  return client.get(`/outlets/${id}`).then((r) => r.data);
}

export function createOutlet(payload) {
  return client.post('/outlets', payload).then((r) => r.data);
}

export function updateOutlet(id, payload) {
  return client.put(`/outlets/${id}`, payload).then((r) => r.data);
}

export function deleteOutlet(id) {
  return client.delete(`/outlets/${id}`);
}
