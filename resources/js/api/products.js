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

/** POST /products/{id}/image — multipart `image` field, gated same as product update. */
export function uploadProductImage(id, file) {
  const form = new FormData();
  form.append('image', file);
  return client.post(`/products/${id}/image`, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}

/** POST /variants/{id}/image — per-variant image, gated on the parent product's update ability. */
export function uploadVariantImage(id, file) {
  const form = new FormData();
  form.append('image', file);
  return client.post(`/variants/${id}/image`, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}

/**
 * Lightweight cashier-facing search — GET /variants/lookup?q=&limit=.
 * 024-invoice-layout-shipping-slip — `q` empty now returns a default
 * browsable page from the backend (no longer short-circuited here), so
 * callers like PreordersView.vue's "Add item" field can show a list on
 * open, matching CustomerSearchDropdown.vue's UX.
 */
export function lookupVariants(q, limit = 20) {
  return client.get('/variants/lookup', { params: { q, limit } }).then((r) => r.data);
}
