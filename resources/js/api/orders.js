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

// 054 (US7) — koreksi metode/kanal satu pembayaran (nominal tak pernah berubah). `channel_id` hanya
// untuk metode non-tunai. Mengembalikan penjualan yang sudah diperbarui.
export function changePaymentMethod(orderId, paymentId, payload) {
  return client.patch(`/orders/${orderId}/payments/${paymentId}/method`, payload).then((r) => r.data);
}

// --- 054 (US8) referensi transaksi: catatan + satu gambar privat ---------------------------------

export function getReference(orderId) {
  return client.get(`/orders/${orderId}/reference`).then((r) => r.data);
}

/**
 * Gambarnya hanya bisa diambil dengan token (endpoint mengotorisasi tiap permintaan), jadi diunduh
 * lewat klien axios lalu dijadikan blob URL — bukan <img src> langsung. Pemanggil wajib
 * `URL.revokeObjectURL` bila sudah tak dipakai.
 */
export function getReferenceImageUrl(orderId) {
  return client.get(`/orders/${orderId}/reference/image`, { responseType: 'blob' }).then((r) => URL.createObjectURL(r.data));
}

/**
 * Multipart harus dikirim sebagai POST + `_method=PUT` (PHP tidak mengurai multipart pada PUT asli).
 * `note` hanya dikirim bila diubah (string kosong mengosongkan catatan); `image` menambah/mengganti.
 */
export function saveReference(orderId, { note, image, removeImage } = {}) {
  const form = new FormData();
  form.append('_method', 'PUT');
  if (note !== undefined) form.append('note', note ?? '');
  if (image) form.append('image', image);
  if (removeImage) form.append('remove_image', '1');
  return client.post(`/orders/${orderId}/reference`, form).then((r) => r.data);
}

export function deleteReference(orderId) {
  return client.delete(`/orders/${orderId}/reference`).then((r) => r.data);
}

/**
 * 054 (US9) — isi struk banyak penjualan untuk unduhan massal: `{ data: [struk…], missing: [id…] }`.
 * Server membatasi 200 id per permintaan; urutan `data` mengikuti urutan `ids`.
 */
export function bulkReceipts(ids) {
  return client.post('/orders/bulk-receipts', { order_ids: ids }).then((r) => r.data);
}
