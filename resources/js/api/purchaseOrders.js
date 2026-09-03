import client from './client';

export function listPurchaseOrders(params = {}) {
  return client.get('/purchase-orders', { params }).then((r) => r.data);
}

export function getPurchaseOrder(id) {
  return client.get(`/purchase-orders/${id}`).then((r) => r.data);
}

export function createPurchaseOrder(payload) {
  return client.post('/purchase-orders', payload).then((r) => r.data);
}

export function updatePurchaseOrder(id, payload) {
  return client.put(`/purchase-orders/${id}`, payload).then((r) => r.data);
}

export function deletePurchaseOrder(id) {
  return client.delete(`/purchase-orders/${id}`);
}

export function updatePurchaseOrderStatus(id, status, cancelReason = null) {
  return client.patch(`/purchase-orders/${id}/status`, { status, cancel_reason: cancelReason }).then((r) => r.data);
}

export function recordPurchaseOrderPayment(id, payload) {
  return client.post(`/purchase-orders/${id}/payments`, payload).then((r) => r.data);
}

export function getPurchaseOrderInvoice(id) {
  return client.get(`/purchase-orders/${id}/invoice`).then((r) => r.data);
}
