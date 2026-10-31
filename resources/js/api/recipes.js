import client from './client';

/**
 * 043-products-page-redesign — resep versi (kontrak api-recipes.md). Biaya hanya dikirim server kepada peran
 * yang berhak; UI tidak menurunkan biaya sendiri.
 */

/** GET /products/{id}/recipes — versi terbaru dulu. */
export function listRecipes(productId) {
  return client.get(`/products/${productId}/recipes`).then((r) => r.data);
}

export function getRecipe(id) {
  return client.get(`/recipes/${id}`).then((r) => r.data);
}

/** POST /products/{id}/recipes — membuat versi BERIKUTNYA; versi lama tak berubah. */
export function createRecipe(productId, payload) {
  return client.post(`/products/${productId}/recipes`, payload).then((r) => r.data);
}

/** 2026-10-31 — DELETE /products/{id}/recipes: hapus resep (semua versi); harga modal varian bisa diubah lagi. */
export function removeRecipe(productId) {
  return client.delete(`/products/${productId}/recipes`).then((r) => r.data);
}

/** POST /products/{id}/recipes/recalculate — "Hitung ulang biaya" eksplisit. */
export function recalculateRecipeCost(productId) {
  return client.post(`/products/${productId}/recipes/recalculate`).then((r) => r.data);
}
