import client from './client';

/** Tab terbuka (opsional difilter: { session_id, table_id }). */
export function listTabs(params = {}) {
  return client.get('/tabs', { params }).then((r) => r.data);
}

export function getTab(id) {
  return client.get(`/tabs/${id}`).then((r) => r.data);
}

/** payload: { session_id, table_id?, tab_label?, customer_id?, local_ref? } */
export function openTab(payload) {
  return client.post('/tabs', payload).then((r) => r.data);
}

/** Hanya untuk tab KOSONG. */
export function closeEmptyTab(id) {
  return client.delete(`/tabs/${id}`);
}

/** Setiap perubahan baris mengembalikan tab terbaru — server satu-satunya sumber total. */
export function addTabLine(tabId, payload) {
  return client.post(`/tabs/${tabId}/items`, payload).then((r) => r.data);
}

export function updateTabLine(tabId, lineId, payload) {
  return client.patch(`/tabs/${tabId}/items/${lineId}`, payload).then((r) => r.data);
}

/**
 * Hapus (belum dikirim) atau BATALKAN (sudah dikirim) sebuah baris. `reason` WAJIB untuk baris yang sudah
 * dikirim; setelah item mulai dimasak hanya owner/admin yang boleh (server memeriksa).
 */
export function removeTabLine(tabId, lineId, reason = null) {
  return client.delete(`/tabs/${tabId}/items/${lineId}`, reason ? { data: { reason } } : undefined).then((r) => r.data);
}

/** Batalkan seluruh tab terbuka (owner/admin; alasan wajib; 409 bila sudah ada pembayaran). */
export function voidTab(tabId, reason) {
  return client.post(`/tabs/${tabId}/void`, { reason }).then((r) => r.data);
}

/** payload: { client_ref, payments: [...], discount_amount? } — sebagian atau penuh. */
export function payTab(tabId, payload) {
  return client.post(`/tabs/${tabId}/payments`, payload).then((r) => r.data);
}

/** Kirim semua baris yang belum terkirim → tiket per stasiun. Mengembalikan { tab, tickets }. */
export function sendTab(tabId) {
  return client.post(`/tabs/${tabId}/send`).then((r) => r.data);
}

/** Pindahkan tab ke meja kosong lain. */
export function moveTab(tabId, tableId) {
  return client.post(`/tabs/${tabId}/move`, { table_id: tableId }).then((r) => r.data);
}

/** Gabungkan tab SUMBER ke tab ini; mengembalikan tab tujuan terbaru. */
export function mergeTab(tabId, sourceTabId) {
  return client.post(`/tabs/${tabId}/merge`, { source_tab_id: sourceTabId }).then((r) => r.data);
}

/** payload: { client_ref, items: [{ item_id, qty }], payments: [...], discount_amount? } → { paid_order, tab }. */
export function splitTab(tabId, payload) {
  return client.post(`/tabs/${tabId}/split`, payload).then((r) => r.data);
}
