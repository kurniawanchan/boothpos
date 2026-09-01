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

/** Multipart upload — returns { proof_token, file_size }. */
export function uploadPaymentProof(file, capturedVia) {
  const form = new FormData();
  form.append('file', file);
  form.append('captured_via', capturedVia);
  return client
    .post('/payment-proofs', form, { headers: { 'Content-Type': 'multipart/form-data' } })
    .then((r) => r.data);
}
