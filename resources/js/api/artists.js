import client from './client';

export function listArtists(params = {}) {
  return client.get('/artists', { params }).then((r) => r.data);
}

export function getArtist(id) {
  return client.get(`/artists/${id}`).then((r) => r.data);
}

export function createArtist(payload) {
  return client.post('/artists', payload).then((r) => r.data);
}

export function updateArtist(id, payload) {
  return client.put(`/artists/${id}`, payload).then((r) => r.data);
}

export function deleteArtist(id) {
  return client.delete(`/artists/${id}`);
}
