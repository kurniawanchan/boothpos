import client from './client';

export function listEvents(params = {}) {
  return client.get('/events', { params }).then((r) => r.data);
}

export function getEvent(id) {
  return client.get(`/events/${id}`).then((r) => r.data);
}

export function createEvent(payload) {
  return client.post('/events', payload).then((r) => r.data);
}

export function updateEvent(id, payload) {
  return client.put(`/events/${id}`, payload).then((r) => r.data);
}

export function updateEventStatus(id, status) {
  return client.patch(`/events/${id}/status`, { status }).then((r) => r.data);
}
