import client from './client';

/**
 * 043-products-page-redesign — kode alasan (86, diskontinu, tolak persetujuan). Daftar terbuka untuk semua
 * pengguna yang login; `include_inactive=1` hanya dihormati server untuk menu `settings`.
 */
export function listReasonCodes(params = {}) {
  return client.get('/reason-codes', { params }).then((r) => r.data);
}

/** POST /reason-codes — { category, code, label_id, label_en, sort?, is_active? }. */
export function createReasonCode(payload) {
  return client.post('/reason-codes', payload).then((r) => r.data);
}

/** PUT /reason-codes/{id} — label, urutan, status. Kategori dan kode tidak berubah; kode tak pernah dihapus. */
export function updateReasonCode(id, payload) {
  return client.put(`/reason-codes/${id}`, payload).then((r) => r.data);
}
