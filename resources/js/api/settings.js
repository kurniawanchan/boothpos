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

/** Multipart — logo toko ditulis lewat endpoint khusus, bukan PUT /settings
 * (lihat research.md Decision 3: body JSON bulk tidak bisa membawa file). */
export function uploadStoreLogo(file) {
  const form = new FormData();
  form.append('image', file);
  return client.post('/settings/store-logo', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}
