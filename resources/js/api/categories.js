import client from './client';

export function listCategories(params = {}) {
  return client.get('/categories', { params }).then((r) => r.data);
}

export function createCategory(payload) {
  return client.post('/categories', payload).then((r) => r.data);
}

export function updateCategory(id, payload) {
  return client.put(`/categories/${id}`, payload).then((r) => r.data);
}

export function deleteCategory(id) {
  return client.delete(`/categories/${id}`);
}
