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
