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

export function activateCompany(id) {
  return client.post(`/companies/${id}/activate`).then((r) => r.data);
}

export function deactivateCompany(id) {
  return client.post(`/companies/${id}/deactivate`).then((r) => r.data);
}

export function updateCompany(id, payload) {
  return client.put(`/companies/${id}`, payload).then((r) => r.data);
}

export function deleteCompany(id) {
  return client.delete(`/companies/${id}`).then((r) => r.data);
}
