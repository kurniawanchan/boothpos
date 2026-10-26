import client from './client';

// 076-settlement-tab-session-branding — settlement seller. Semua aksi tulis
// mengembalikan DETAIL settlement terbaru (`{ data }`), jadi layar cukup
// mengganti state-nya tanpa memuat ulang.

export function listSettlements(params = {}) {
  return client.get('/settlements', { params }).then((r) => r.data);
}

export function previewSettlement(eventId, artistId) {
  return client.get('/settlements/preview', { params: { event_id: eventId, artist_id: artistId } }).then((r) => r.data);
}

export function createSettlement(eventId, artistId) {
  return client.post('/settlements', { event_id: eventId, artist_id: artistId }).then((r) => r.data);
}

export function getSettlement(id) {
  return client.get(`/settlements/${id}`).then((r) => r.data);
}

export function refreshSettlement(id) {
  return client.post(`/settlements/${id}/refresh`).then((r) => r.data);
}

export function deleteSettlement(id) {
  return client.delete(`/settlements/${id}`);
}

export function finalizeSettlement(id) {
  return client.post(`/settlements/${id}/finalize`).then((r) => r.data);
}

export function reopenSettlement(id) {
  return client.post(`/settlements/${id}/reopen`).then((r) => r.data);
}

export function addSettlementAdjustment(id, payload) {
  return client.post(`/settlements/${id}/adjustments`, payload).then((r) => r.data);
}

export function updateSettlementAdjustment(id, adjustmentId, payload) {
  return client.put(`/settlements/${id}/adjustments/${adjustmentId}`, payload).then((r) => r.data);
}

export function deleteSettlementAdjustment(id, adjustmentId) {
  return client.delete(`/settlements/${id}/adjustments/${adjustmentId}`).then((r) => r.data);
}

// --- US3: pengakuan seller, pembayaran, berkas privat, dokumen ---------------

/** Tanda tangan dikirim sebagai berkas PNG (multipart); nama/catatan sebagai teks. */
export function acknowledgeSettlement(id, { method, signerName, note, signature }) {
  const form = new FormData();
  form.append('method', method);
  if (signerName) form.append('signer_name', signerName);
  if (note) form.append('note', note);
  if (signature) form.append('signature', signature, 'signature.png');

  return client.post(`/settlements/${id}/acknowledgement`, form).then((r) => r.data);
}

/** Gambar tanda tangan diambil lewat endpoint yang memeriksa hak akses (bukan URL publik). */
export function getAcknowledgementSignature(id, acknowledgementId) {
  return client
    .get(`/settlements/${id}/acknowledgements/${acknowledgementId}/signature`, { responseType: 'blob' })
    .then((r) => r.data);
}

export function addSettlementPayment(id, payload, proof = null) {
  const form = new FormData();
  Object.entries(payload).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') form.append(key, value);
  });
  if (proof) form.append('proof', proof);

  return client.post(`/settlements/${id}/payments`, form).then((r) => r.data);
}

export function deleteSettlementPayment(id, paymentId) {
  return client.delete(`/settlements/${id}/payments/${paymentId}`).then((r) => r.data);
}

export function getSettlementPaymentProof(id, paymentId) {
  return client.get(`/settlements/${id}/payments/${paymentId}/proof`, { responseType: 'blob' }).then((r) => r.data);
}

export function getSettlementDocument(id, includeCost = false) {
  return client.get(`/settlements/${id}/document`, { params: { include_cost: includeCost ? 1 : 0 } }).then((r) => r.data);
}

export function getSettlementPaymentDocument(id, paymentId) {
  return client.get(`/settlements/${id}/payments/${paymentId}/document`).then((r) => r.data);
}
