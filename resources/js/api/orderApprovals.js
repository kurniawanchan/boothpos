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
