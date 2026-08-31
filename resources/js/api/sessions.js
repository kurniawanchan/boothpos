import client from './client';

/** Resolves null (not an error) when there is no open session — a 404 here is expected. */
export function currentSession() {
  return client
    .get('/sessions/current')
    .then((r) => r.data)
    .catch((err) => {
      if (err.status === 404) return null;
      throw err;
    });
}

export function openSession(payload) {
  return client.post('/sessions', payload).then((r) => r.data);
}

export function closeSession(id, payload) {
  return client.post(`/sessions/${id}/close`, payload).then((r) => r.data);
}

export function sessionSummary(id) {
  return client.get(`/sessions/${id}/summary`).then((r) => r.data);
}
