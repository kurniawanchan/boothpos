import client from './client';

export function listProducts(params = {}) {
  return client.get('/products', { params }).then((r) => r.data);
}

export function getProduct(id) {
  return client.get(`/products/${id}`).then((r) => r.data);
}

export function createProduct(payload) {
  return client.post('/products', payload).then((r) => r.data);
}

export function updateProduct(id, payload) {
  return client.put(`/products/${id}`, payload).then((r) => r.data);
}

export function deleteProduct(id) {
  return client.delete(`/products/${id}`);
}

export function addVariant(productId, payload) {
  return client.post(`/products/${productId}/variants`, payload).then((r) => r.data);
}

export function updateVariant(variantId, payload) {
  return client.put(`/variants/${variantId}`, payload).then((r) => r.data);
}

/** Lightweight cashier-facing search — GET /variants/lookup?q=&limit= */
export function lookupVariants(q, limit = 20) {
  if (!q) return Promise.resolve({ data: [] });
  return client.get('/variants/lookup', { params: { q, limit } }).then((r) => r.data);
}
