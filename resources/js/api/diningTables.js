import client from './client';

/** GET /dining-tables — tidak berpaginasi (jumlah meja terbatas secara fisik). */
export function listDiningTables(params = {}) {
  return client.get('/dining-tables', { params }).then((r) => r.data);
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
