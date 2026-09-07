import client from './client';

// 019-billing-system — Phase 2 (tasks.md T038): `License` menggantikan
// `Package` sepenuhnya (research.md R1'), jadi ini bukan lagi sebuah
// stopgap yang menunjuk endpoint lama — file dan nama fungsinya sendiri
// sekarang mencerminkan entitas barunya. `packages.js`/`PackagesView.vue`
// sudah dihapus di komit yang sama.
export function listLicenses(params = {}) {
  return client.get('/licenses', { params }).then((r) => r.data);
}

export function createLicense(payload) {
  return client.post('/licenses', payload).then((r) => r.data);
}

export function updateLicense(id, payload) {
  return client.put(`/licenses/${id}`, payload).then((r) => r.data);
}

export function deleteLicense(id) {
  return client.delete(`/licenses/${id}`);
}
