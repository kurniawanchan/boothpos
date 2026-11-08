import client from './client';

// 059 — persetujuan seller atas item penjualan POS. Server menentukan SEMUANYA (siapa penyetuju, waktu,
// status, hak); klien hanya mengirim seller mana, metodenya, gambar, dan `items_hash` yang tadi diterimanya
// (hash itu hanya bisa membuat permintaan GAGAL bila item berubah — tak pernah membuatnya berhasil).

/** Status persetujuan satu penjualan: syarat per seller + status keseluruhan (+ riwayat bagi owner/admin). */
export function getApprovals(orderId) {
  return client.get(`/orders/${orderId}/approvals`).then((r) => r.data.data);
}

/**
 * Catat persetujuan. Multipart (gambar untuk `draw`/`upload`; stempel tanpa berkas). Mengembalikan status terbaru.
 * 409 `already_approved` / `approval_stale` / `order_voided` ditoast sekali oleh klien API; pemanggil hanya
 * menyesuaikan tampilan (muat ulang) berdasarkan `err.code`.
 */
export function approveSeller(orderId, { artistId, method, itemsHash, image = null }) {
  const form = new FormData();
  form.append('artist_id', String(artistId));
  form.append('method', method);
  form.append('items_hash', itemsHash);
  if (image) form.append('image', image, image.name || 'approval.png');
  return client.post(`/orders/${orderId}/approvals`, form).then((r) => r.data.data);
}

/** Gambar tanda tangan hanya bisa diambil dengan token → blob URL (pemanggil wajib me-revoke). */
export function getApprovalImageBlobUrl(orderId, approvalId) {
  return client
    .get(`/orders/${orderId}/approvals/${approvalId}/image`, { responseType: 'blob' })
    .then((r) => URL.createObjectURL(r.data));
}

// 064 — persetujuan massal dari daftar Sales. Server menentukan SEMUANYA per penjualan (hak, seller, batal, siklus,
// kesegaran); klien hanya mengirim id yang dipilih, metode, gambar, dan `plan` (hash isi yang tadi ditinjau di
// pratinjau — hanya bisa membuat sebuah penjualan DILEWATI, tak pernah membuatnya berhasil).

/** Pratinjau (hanya baca): jumlah, seller, nilai, yang dilewati + alasannya, dan `plan` untuk langkah terapkan. */
export function previewBulkApproval(orderIds) {
  return client.post('/orders/approvals/bulk/preview', { order_ids: orderIds }).then((r) => r.data.data);
}

/**
 * Terapkan. Multipart: `order_ids` dan `plan` dikirim sebagai SATU string JSON per kolom (array bersarang ratusan
 * penjualan melewati `max_input_vars` PHP). Selalu 200 bila bentuk valid; masalah per penjualan ada di `results`.
 */
export function bulkApprove({ orderIds, plan, method, image = null }) {
  const form = new FormData();
  form.append('order_ids', JSON.stringify(orderIds));
  form.append('plan', JSON.stringify(plan));
  form.append('method', method);
  if (image) form.append('image', image, image.name || 'approval.png');
  return client.post('/orders/approvals/bulk', form).then((r) => r.data.data);
}
