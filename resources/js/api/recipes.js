import client from './client';

/**
 * 043-products-page-redesign — resep (kontrak api-recipes.md). 089: tanpa versi; satu varian boleh punya banyak resep
 * aktif dan semuanya dijumlahkan. Biaya hanya dikirim server kepada peran yang berhak; UI tidak menurunkan biaya sendiri.
 */

/** GET /products/{id}/recipes — semua resep (termasuk arsip), diurutkan menurut nama. */
export function listRecipes(productId) {
  return client.get(`/products/${productId}/recipes`).then((r) => r.data);
}

export function getRecipe(id) {
  return client.get(`/recipes/${id}`).then((r) => r.data);
}

/** POST /products/{id}/recipes — membuat SATU resep baru; tidak menimpa resep lain. */
export function createRecipe(productId, payload) {
  return client.post(`/products/${productId}/recipes`, payload).then((r) => r.data);
}

/** 089 — PUT /recipes/{id}: mengubah resep di tempat (tanpa versi). 409 `recipe_archived` untuk resep arsip. */
export function updateRecipe(id, payload) {
  return client.put(`/recipes/${id}`, payload).then((r) => r.data);
}

/** 089 — DELETE /recipes/{id}: hapus SATU resep. */
export function removeOneRecipe(id) {
  return client.delete(`/recipes/${id}`).then((r) => r.data);
}

/** 2026-10-31 — DELETE /products/{id}/recipes: hapus SEMUA resep item; harga modal varian bisa diubah lagi. */
export function removeRecipe(productId) {
  return client.delete(`/products/${productId}/recipes`).then((r) => r.data);
}

/** POST /products/{id}/recipes/recalculate — "Hitung ulang biaya" eksplisit. */
export function recalculateRecipeCost(productId) {
  return client.post(`/products/${productId}/recipes/recalculate`).then((r) => r.data);
}

/** GET /products/{id}/recipe-modifier-options — grup modifier efektif item + pilihan aktifnya (untuk tabel bahan bermodifier). */
export function listRecipeModifierOptions(productId) {
  return client.get(`/products/${productId}/recipe-modifier-options`).then((r) => r.data?.data ?? []);
}

/**
 * 093 — GET /recipe-ingredients: varian produk bertanda "Gunakan sebagai bahan" yang boleh dipilih di resep BARU, dengan
 * stok tersedia di outlet yang berlaku (null bila outlet tidak tunggal). Mengembalikan `{data, meta}`.
 */
export function listRecipeIngredients(params = {}) {
  return client.get('/recipe-ingredients', { params }).then((r) => r.data);
}
