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
