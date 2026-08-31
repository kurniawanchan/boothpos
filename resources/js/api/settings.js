import client from './client';

export function featureFlags() {
  return client.get('/settings/features').then((r) => r.data);
}

export function listSettings() {
  return client.get('/settings').then((r) => r.data);
}

/** payload: [{ key, value, type?, group? }, ...] */
export function updateSettings(settings) {
  return client.put('/settings', { settings }).then((r) => r.data);
}
