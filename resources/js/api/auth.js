import client from './client';

export function login(username, password) {
  return client.post('/auth/login', { username, password }).then((r) => r.data);
}

export function logout() {
  return client.post('/auth/logout');
}

export function me() {
  return client.get('/auth/me').then((r) => r.data);
}

export function updateLanguage(language) {
  return client.put('/auth/language', { language }).then((r) => r.data);
}

// 005-ux-enhancements-dashboard (US3) — swa-layanan, lihat komentar
// AuthController::updatePassword().
export function updatePassword(payload) {
  return client.put('/auth/password', payload).then((r) => r.data);
}

// 005-ux-enhancements-dashboard (US3) — swa-layanan, sengaja bukan
// POST /users/{user}/photo (lihat AuthController::updatePhoto()).
export function updatePhoto(file) {
  const form = new FormData();
  form.append('image', file);
  return client.post('/auth/photo', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}
