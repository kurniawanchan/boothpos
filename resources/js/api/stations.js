import client from './client';

/**
 * GET /stations — tidak berpaginasi. Terbuka untuk `dining_setup` ATAU `kitchen`. 071: `params.outlet_id` (array)
 * menyaring outlet; halaman Restaurant Setup juga meminta `skipOutletHeader`. Pemanggil lain tidak berubah.
 */
export function listStations(params = {}, options = {}) {
  return client.get('/stations', { params, ...(options.skipOutletHeader ? { skipOutletHeader: true } : {}) }).then((r) => r.data);
}

export function createStation(payload) {
  return client.post('/stations', payload).then((r) => r.data);
}

export function updateStation(id, payload) {
  return client.put(`/stations/${id}`, payload).then((r) => r.data);
}

export function deleteStation(id) {
  return client.delete(`/stations/${id}`);
}
