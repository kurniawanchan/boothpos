import client from './client';

export function listPaymentChannels() {
  return client.get('/payment-channels').then((r) => r.data);
}

/** Shared multipart builder for create/update — POST is used for both since
 * update needs to carry a file (`qr_image`) and Laravel doesn't parse
 * multipart bodies on PUT/PATCH without a _method spoof. */
function buildChannelForm(payload, { qrImage = null, removeQrImage = false } = {}) {
  const form = new FormData();
  Object.entries(payload).forEach(([key, value]) => {
    if (value === null || value === undefined) return;
    form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
  });
  if (qrImage) form.append('qr_image', qrImage);
  if (removeQrImage) form.append('remove_qr_image', '1');
  return form;
}

export function createPaymentChannel(payload, opts = {}) {
  return client
    .post('/payment-channels', buildChannelForm(payload, opts), { headers: { 'Content-Type': 'multipart/form-data' } })
    .then((r) => r.data);
}

/** POST /payment-channels/{id} — update, including replacing/removing the QR image. */
export function updatePaymentChannel(id, payload, opts = {}) {
  return client
    .post(`/payment-channels/${id}`, buildChannelForm(payload, opts), { headers: { 'Content-Type': 'multipart/form-data' } })
    .then((r) => r.data);
}

/** DELETE /payment-channels/{id} — soft-delete a payment channel. */
export function deletePaymentChannel(id) {
  return client.delete(`/payment-channels/${id}`).then((r) => r.data);
}

/** Multipart upload — returns { proof_token, file_size }. */
export function uploadPaymentProof(file, capturedVia) {
  const form = new FormData();
  form.append('file', file);
  form.append('captured_via', capturedVia);
  return client
    .post('/payment-proofs', form, { headers: { 'Content-Type': 'multipart/form-data' } })
    .then((r) => r.data);
}

/**
 * 024-invoice-layout-shipping-slip — bukti pembayaran disimpan di disk
 * privat dan disajikan lewat endpoint berotorisasi (GET /payment-proofs/
 * {id}/file), BUKAN URL publik seperti logo/QR — jadi tidak bisa dipakai
 * langsung sebagai <img src>, karena tag <img> tidak mengirim header
 * Authorization axios. Diambil sebagai blob lalu dibuatkan object URL
 * sementara; pemanggil bertanggung jawab me-revoke saat sudah tidak
 * dipakai (mis. saat lightbox ditutup).
 */
export function getPaymentProofBlobUrl(proofId) {
  return client.get(`/payment-proofs/${proofId}/file`, { responseType: 'blob' }).then((r) => URL.createObjectURL(r.data));
}
