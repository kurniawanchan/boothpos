import client from './client';

export function listPaymentChannels() {
  return client.get('/payment-channels').then((r) => r.data);
}

export function createPaymentChannel(payload) {
  return client.post('/payment-channels', payload).then((r) => r.data);
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
