import client from './client';

export function listUsers(params = {}) {
  return client.get('/users', { params }).then((r) => r.data);
}

export function getUser(id) {
  return client.get(`/users/${id}`).then((r) => r.data);
}

export function createUser(payload) {
  return client.post('/users', payload).then((r) => r.data);
}

export function updateUser(id, payload) {
  return client.put(`/users/${id}`, payload).then((r) => r.data);
}

/** 045 Stage 2 — mengganti seluruh penugasan outlet pengguna (Owner/Admin ditolak server dengan 409). */
export function setUserOutlets(id, outletIds) {
  return client.put(`/users/${id}/outlets`, { outlet_ids: outletIds }).then((r) => r.data);
}

export function deleteUser(id) {
  return client.delete(`/users/${id}`);
}

export function uploadUserPhoto(id, file) {
  const form = new FormData();
  form.append('image', file);
  return client.post(`/users/${id}/photo`, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}

/**
 * 074 — owner/admin mengatur PIN pengguna. `source` ('typed' | 'generated') hanya penanda log. PIN dikirim sekali dan tidak
 * disimpan di sini; respons berisi pengguna (`has_pin`), bukan PIN.
 */
export function setUserPin(id, pin, source = 'typed') {
  return client.put(`/users/${id}/pin`, { pin, source }).then((r) => r.data);
}

/** 074 — saran PIN acak untuk tombol "Generate" (tidak disimpan di server sampai admin menyimpannya). */
export function suggestPin() {
  return client.post('/pin-suggestions').then((r) => r.data);
}
