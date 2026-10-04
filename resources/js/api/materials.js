import client from './client';

export function listMaterials(params = {}) {
  return client.get('/materials', { params }).then((r) => r.data);
}

export function getMaterial(id) {
  return client.get(`/materials/${id}`).then((r) => r.data);
}

export function createMaterial(payload) {
  return client.post('/materials', payload).then((r) => r.data);
}

export function updateMaterial(id, payload) {
  return client.put(`/materials/${id}`, payload).then((r) => r.data);
}

export function deleteMaterial(id) {
  return client.delete(`/materials/${id}`);
}

// Harga vendor per bahan — digantung di bawah /materials/{material}/vendor-prices
// (bahan sebagai aggregate root), lihat routes/api.php.
export function addVendorPrice(materialId, payload) {
  return client.post(`/materials/${materialId}/vendor-prices`, payload).then((r) => r.data);
}

export function updateVendorPrice(id, payload) {
  return client.put(`/vendor-prices/${id}`, payload).then((r) => r.data);
}

export function deleteVendorPrice(id) {
  return client.delete(`/vendor-prices/${id}`);
}

// BOM per varian produk — bersumber baris purchase order (034-seller-po-bom).
// Semua respons baca/mutasi berbentuk { data: baris[], summary }.
export function listBomLines(variantId) {
  return client.get(`/variants/${variantId}/bom`).then((r) => r.data);
}

// Baris PO yang boleh dipilih (hanya milik seller varian ini, status
// ordered/received/paid). Mengembalikan { data, meta }.
export function eligibleBomLines(variantId, params = {}) {
  return client.get(`/variants/${variantId}/bom/eligible-lines`, { params }).then((r) => r.data);
}

// items: [{ purchase_order_item_id, qty? }] — biaya disalin server dari baris PO.
export function addBomItems(variantId, items) {
  return client.post(`/variants/${variantId}/bom/items`, { items }).then((r) => r.data);
}

export function updateBomLine(id, payload) {
  return client.put(`/bom/${id}`, payload).then((r) => r.data);
}

export function deleteBomLine(id) {
  return client.delete(`/bom/${id}`).then((r) => r.data);
}

// Tandai BOM selesai: harga modal varian mengikuti biaya BOM dan dikunci.
export function completeBom(variantId) {
  return client.post(`/variants/${variantId}/bom/complete`).then((r) => r.data);
}

export function reopenBom(variantId) {
  return client.post(`/variants/${variantId}/bom/reopen`).then((r) => r.data);
}

// Salin BOM antar varian satu produk. confirm_replace wajib bila target sudah punya baris.
export function copyBomFrom(variantId, sourceVariantId, confirmReplace = false) {
  return client.post(`/variants/${variantId}/bom/copy`, { mode: 'from', source_variant_id: sourceVariantId, confirm_replace: confirmReplace }).then((r) => r.data);
}

export function copyBomOut(variantId, mode, confirmReplace = false) {
  return client.post(`/variants/${variantId}/bom/copy-out`, { mode, confirm_replace: confirmReplace }).then((r) => r.data);
}

// Ganti sumber baris BOM ke baris PO lain (mis. "pakai harga terbaru") — tindakan eksplisit.
export function replaceBomSource(bomLineId, purchaseOrderItemId) {
  return client.post(`/bom/${bomLineId}/replace-source`, { purchase_order_item_id: purchaseOrderItemId }).then((r) => r.data);
}

export function getCostBreakdown(variantId) {
  return client.get(`/variants/${variantId}/cost-breakdown`).then((r) => r.data);
}
