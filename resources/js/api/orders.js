import client from './client';

export function listOrders(params = {}) {
  return client.get('/orders', { params }).then((r) => r.data);
}

export function getOrder(id) {
  return client.get(`/orders/${id}`).then((r) => r.data);
}

export function createOrder(payload) {
  return client.post('/orders', payload).then((r) => r.data);
}

export function voidOrder(id, reason) {
  return client.post(`/orders/${id}/void`, { reason }).then((r) => r.data);
}

export function getReceipt(id) {
  return client.get(`/orders/${id}/receipt`).then((r) => r.data);
}
