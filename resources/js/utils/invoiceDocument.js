/**
 * Pembuat HTML untuk SURAT JALAN (bulk unduh ZIP). Invoice dan payment invoice
 * TIDAK lagi dibuat di sini: sejak 029-fix-bulk-invoice-logo keduanya dirender
 * dari komponen yang sama dengan modalnya (components/preorder/
 * PreorderInvoiceDocument.vue dan PreorderPaymentDocument.vue), supaya tata
 * letak unduh massal tidak bisa menyimpang dari dokumen di layar. Jangan
 * menambah tata letak invoice baru di sini.
 */
function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/**
 * 024-invoice-layout-shipping-slip (US7) — builds shipping slip HTML for
 * single/bulk PDF export, matching the hidden template in PreordersView.vue.
 */
export function buildShippingSlipHtml(invoice) {
  const storeIdentity = invoice.store_identity;
  const customer = invoice.customer;

  return `
    <div style="width:420px;padding:24px;background:#fff;font-family:Arial,sans-serif;color:#1a1a1a;font-size:12px;">
      <div style="text-align:center;padding:8px 0;border-bottom:1px dashed #ccc;margin-bottom:12px;">
        <div style="font-size:14px;font-weight:700;">Shipping Info</div>
        <div style="font-family:monospace;font-weight:700;margin-top:4px;font-size:13px;">${escapeHtml(invoice.preorder_number)}</div>
      </div>

      <div style="display:flex;gap:12px;margin-bottom:12px;">
        <div style="flex:1;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:3px;">From</div>
          <div style="font-size:12px;">${escapeHtml(storeIdentity?.name || '')}</div>
          ${storeIdentity?.address ? `<div style="font-size:10px;color:#666;">${escapeHtml(storeIdentity.address)}</div>` : ''}
          ${storeIdentity?.contact_phone ? `<div style="font-size:10px;color:#666;">${escapeHtml(storeIdentity.contact_phone)}</div>` : ''}
          ${storeIdentity?.contact_person ? `<div style="font-size:10px;color:#666;">${escapeHtml(storeIdentity.contact_person)}</div>` : ''}
        </div>
        <div style="flex:1;">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:3px;">To</div>
          <div style="font-size:12px;">${escapeHtml(customer?.name || '')}</div>
          <div style="font-size:10px;color:#666;">${escapeHtml(customer?.phone || '')}</div>
          <div style="font-size:10px;color:#666;">${escapeHtml(customer?.address || '')}</div>
        </div>
      </div>
    </div>
  `;
}
