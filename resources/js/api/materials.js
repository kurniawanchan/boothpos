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

// BOM per varian produk — digantung di bawah /variants/{variant}/bom.
export function listBomLines(variantId) {
  return client.get(`/variants/${variantId}/bom`).then((r) => r.data);
}

export function addBomLine(variantId, payload) {
  return client.post(`/variants/${variantId}/bom`, payload).then((r) => r.data);
}

export function updateBomLine(id, payload) {
  return client.put(`/bom/${id}`, payload).then((r) => r.data);
}

export function deleteBomLine(id) {
  return client.delete(`/bom/${id}`);
}

export function getCostBreakdown(variantId) {
  return client.get(`/variants/${variantId}/cost-breakdown`).then((r) => r.data);
}
