import client from './client';

export function listPosDrafts() {
  return client.get('/pos-drafts').then((r) => r.data);
}

export function savePosDraft(payload) {
  return client.post('/pos-drafts', payload).then((r) => r.data);
}

export function resumePosDraft(id) {
  return client.get(`/pos-drafts/${id}`).then((r) => r.data);
}

export function discardPosDraft(id) {
  return client.delete(`/pos-drafts/${id}`);
}
