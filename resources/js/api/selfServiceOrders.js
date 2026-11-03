import client from './client';

// 090 / US6 — tab Self-Service untuk STAF (butuh login + menu `self_service`). Bukan halaman pelanggan: itu `api/selfService.js`.

/** params: { status: [..], table_id, q, page, per_page } → { data, meta: { ..., counts } }. */
export function listSelfServiceOrders(params = {}) {
  return client.get('/self-service/orders', { params }).then((r) => r.data);
}

export function getSelfServiceOrder(id) {
  return client.get(`/self-service/orders/${id}`).then((r) => r.data.data);
}

/** Verifikasi pembayaran; `reference` minimal 4 karakter (referensi dari mutasi/aplikasi bank). */
export function verifySelfServiceOrder(id, reference) {
  return client.post(`/self-service/orders/${id}/verify`, { reference }).then((r) => r.data.data);
}

export function rejectSelfServiceOrder(id, reason) {
  return client.post(`/self-service/orders/${id}/reject`, { reason }).then((r) => r.data.data);
}

/** "Kirim ke dapur" → { data, order: { id, order_number }, tickets }. */
export function dispatchSelfServiceOrder(id) {
  return client.post(`/self-service/orders/${id}/dispatch`).then((r) => r.data);
}

/** Foto bukti bayar sebagai Blob lewat jalur berotorisasi (tidak ada URL publik). */
export function fetchProofBlob(proofId) {
  return client.get(`/payment-proofs/${proofId}/file`, { responseType: 'blob' }).then((r) => r.data);
}
