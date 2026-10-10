import client from './client';

/** GET /stations — tidak berpaginasi. Terbuka untuk `dining_setup` ATAU `kitchen`. */
export function listStations(params = {}) {
  return client.get('/stations', { params }).then((r) => r.data);
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
