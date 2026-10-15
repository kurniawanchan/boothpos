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

// 028-partial-split-payment (US5) — pembayaran susulan atas penjualan POS yang dibayar
// sebagian. Payload memuat `client_ref` (UUID, idempotensi). 201 = baru, 200 = replay.
export function addOrderPayment(id, payload) {
  return client.post(`/orders/${id}/payments`, payload).then((r) => r.data);
}

// Hapus satu pembayaran (owner/admin saja; 409 untuk order batal / tunai shift yang sudah ditutup).
export function deleteOrderPayment(orderId, paymentId) {
  return client.delete(`/orders/${orderId}/payments/${paymentId}`).then((r) => r.data);
}
