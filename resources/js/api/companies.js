import client from './client';

export function listCompanies(params = {}) {
  return client.get('/companies', { params }).then((r) => r.data);
}

export function getCompany(id) {
  return client.get(`/companies/${id}`).then((r) => r.data);
}

export function createCompany(payload) {
  return client.post('/companies', payload).then((r) => r.data);
}

export function resendActivation(id) {
  return client.post(`/companies/${id}/resend-activation`).then((r) => r.data);
}

export function activateCompany(id, code) {
  return client.post(`/companies/${id}/activate`, { code }).then((r) => r.data);
}
