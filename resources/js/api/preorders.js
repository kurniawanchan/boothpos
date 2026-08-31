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
