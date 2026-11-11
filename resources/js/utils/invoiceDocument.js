/**
 * Sisa helper dokumen. Invoice dan payment invoice TIDAK dibuat di sini: sejak 029-fix-bulk-invoice-logo keduanya dirender
 * dari komponen yang sama dengan modalnya (components/preorder/
 * PreorderInvoiceDocument.vue dan PreorderPaymentDocument.vue), supaya tata
 * letak unduh massal tidak bisa menyimpang dari dokumen di layar. Jangan
 * menambah tata letak invoice baru di sini. Surat jalan massal kini dibuat di
 * utils/shippingSlipSheet.js (feature 067); file ini hanya menyisakan
 * `escapeHtml` yang dipakai bersama.
 */
export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}
