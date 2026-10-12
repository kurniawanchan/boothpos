import client from './client';

export function listProducts(params = {}) {
  return client.get('/products', { params }).then((r) => r.data);
}

/**
 * 043 — angka kepala halaman Produk (menu item aktif, 86 hari ini, belum dirilis, kualitas data).
 * Satu panggilan; tiap angka dihitung server dari data yang sama dengan daftar (SC-001).
 */
export function productSummary() {
  return client.get('/products/summary').then((r) => r.data);
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
export function lookupVariants(q, limit = 20, { pos = false, stockOutlet = null } = {}) {
  // 045 Stage 4: `pos` = layar pemesanan — menu/harga menurut outlet SHIFT pengguna.
  // 045 Stage 5: `stockOutlet` = layar Stok — hasil membawa `outlet_stock` (saldo di outlet itu).
  return client.get('/variants/lookup', { params: { q, limit, ...(pos ? { pos: 1 } : {}), ...(stockOutlet ? { stock_outlet: stockOutlet } : {}) } }).then((r) => r.data);
}

// --- 043-products-page-redesign: tindakan item (kontrak api-products.md) ---------------------------------------
// Semua izin ditegakkan server; pembungkus ini hanya mengirim. 409 (sudah 86, sudah diskontinu) dan 422 (alasan
// kosong) ditangani handler galat pusat.

/** POST /products/{id}/sold-out — { reason_code_id (wajib), note? }. */
export function soldOut(id, payload) {
  return client.post(`/products/${id}/sold-out`, payload).then((r) => r.data);
}

/** DELETE /products/{id}/sold-out — batalkan 86 aktif. */
export function undoSoldOut(id, outletIds = null) {
  const url = `/products/${id}/sold-out`;
  const request = outletIds?.length ? client.delete(url, { data: { outlet_ids: outletIds } }) : client.delete(url);

  return request.then((r) => r.data);
}

/** POST /products/{id}/discontinue — { reason_code_id, note? }; bila rilis aktif, mengembalikan perubahan tertunda. */
export function discontinueProduct(id, payload) {
  return client.post(`/products/${id}/discontinue`, payload).then((r) => r.data);
}

export function reactivateProduct(id, payload = {}) {
  return client.post(`/products/${id}/reactivate`, payload).then((r) => r.data);
}

/** GET /products/{id}/price-history — terbaru dulu, berpaginasi. */
export function priceHistory(id, params = {}) {
  return client.get(`/products/${id}/price-history`, { params }).then((r) => r.data);
}

/** PUT /products/{id}/variants — batch seluruh varian (semua-atau-tidak-sama-sekali); respons { applied, pending }. */
export function saveVariants(id, payload) {
  return client.put(`/products/${id}/variants`, payload).then((r) => r.data);
}

/** PUT /products/{id}/modifier-links — { links: [{ group_id, is_required, min_select, max_select, display_order }] }. */
export function saveModifierLinks(id, links) {
  return client.put(`/products/${id}/modifier-links`, { links }).then((r) => r.data);
}

/** 045 Stage 3 — harga varian di satu outlet; `price: null` menghapus harga khusus (kembali mengikuti dasar). */
export function setVariantOutletPrice(variantId, outletId, price) {
  return client.put(`/variants/${variantId}/outlet-prices/${outletId}`, { price }).then((r) => r.data);
}
