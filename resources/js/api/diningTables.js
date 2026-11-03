import client from './client';

/**
 * GET /dining-tables — tidak berpaginasi (jumlah meja terbatas secara fisik). 071: `params.outlet_id` (array) menyaring
 * outlet; halaman Restaurant Setup juga meminta `skipOutletHeader` (filter-nya sendiri menggantikan header bilah atas).
 */
export function listDiningTables(params = {}, options = {}) {
  return client.get('/dining-tables', { params, ...(options.skipOutletHeader ? { skipOutletHeader: true } : {}) }).then((r) => r.data);
}

export function createDiningTable(payload) {
  return client.post('/dining-tables', payload).then((r) => r.data);
}

export function updateDiningTable(id, payload) {
  return client.put(`/dining-tables/${id}`, payload).then((r) => r.data);
}

export function deleteDiningTable(id) {
  return client.delete(`/dining-tables/${id}`);
}

/**
 * 090 / US2 — QR pesan mandiri. `payload`: { ids: [..] } (meja terpilih) ATAU { filter: { q, area, is_active, outlet_id[] } }
 * (semua hasil filter). Maks 200 meja. Mengembalikan { items: [{ table_id, outlet_name, table_name, area, url }], skipped: { inactive } }.
 * 409 `qr_base_url_invalid` bila alamat dasar belum bisa dijangkau ponsel pelanggan.
 */
export function qrBatch(payload, options = {}) {
  return client.post('/dining-tables/qr-batch', payload, options.skipOutletHeader ? { skipOutletHeader: true } : {}).then((r) => r.data);
}

/** 090 / US2 — mencabut QR lama meja (nonce baru); mengembalikan { table_id, url } yang baru. */
export function rotateTableQr(id, options = {}) {
  return client.post(`/dining-tables/${id}/qr/rotate`, null, options.skipOutletHeader ? { skipOutletHeader: true } : {}).then((r) => r.data);
}
