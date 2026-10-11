import client from './client';

/** GET /modifier-groups — tidak berpaginasi; memuat opsi + id produk/kategori yang dilampiri. */
export function listModifierGroups() {
  return client.get('/modifier-groups').then((r) => r.data);
}

export function createModifierGroup(payload) {
  return client.post('/modifier-groups', payload).then((r) => r.data);
}

/** `options` bila dikirim = penggantian PENUH menurut id (yang tidak dikirim dinonaktifkan). */
export function updateModifierGroup(id, payload) {
  return client.put(`/modifier-groups/${id}`, payload).then((r) => r.data);
}

export function deleteModifierGroup(id) {
  return client.delete(`/modifier-groups/${id}`);
}

/** PUT /modifier-groups/{id}/attachments — penggantian penuh: { product_ids, category_ids }. */
export function setModifierGroupAttachments(id, payload) {
  return client.put(`/modifier-groups/${id}/attachments`, payload).then((r) => r.data);
}
