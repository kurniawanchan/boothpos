import { formatIDR } from './money';

/**
 * 022-preorder-invoice-crud-overhaul (US5, FR-013, research.md Decision 5)
 * — builds the SAME visual content PreorderInvoiceModal.vue/
 * PreorderPaymentReceiptModal.vue already render (store header, items,
 * totals, payment terms, footer), as a plain HTML string, so bulk
 * download can rasterize N invoices sequentially without mounting N Vue
 * modal instances. Kept deliberately close to those components' markup —
 * this is the same document, rendered for a batch context, not a second
 * design.
 */
function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function buildInvoiceHtml(invoice, documentType = 'invoice') {
  const payment = documentType === 'payment_invoice' ? (invoice.payments ?? [])[invoice.payments?.length - 1] : null;
  const storeIdentity = invoice.store_identity;
  const channels = invoice.payment_channels ?? [];

  const itemsHtml = (invoice.items ?? [])
    .map(
      (item) => `
        <div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;">
          <span style="min-width:26px;font-weight:700;">${item.qty}×</span>
          <span style="flex:1;font-weight:600;">${escapeHtml(item.name_snapshot)}</span>
          <span style="font-weight:700;">${formatIDR(item.line_total)}</span>
        </div>`
    )
    .join('');

  const channelsHtml = channels
    .map(
      (c) => `
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #ddd;">
          <span style="font-weight:700;">${escapeHtml(c.provider)}</span>
          <span style="font-family:monospace;">${escapeHtml(c.account_number || '')}</span>
        </div>`
    )
    .join('');

  return `
    <div style="width:420px;padding:24px;background:#fff;font-family:Arial,sans-serif;color:#1a1a1a;">
      ${storeIdentity ? `
        <div style="text-align:center;border-bottom:1px dashed #ccc;padding-bottom:14px;margin-bottom:14px;">
          <div style="font-size:17px;font-weight:800;">${escapeHtml(storeIdentity.name)}</div>
          ${storeIdentity.address ? `<div style="font-size:11px;color:#666;">${escapeHtml(storeIdentity.address)}</div>` : ''}
        </div>` : ''}

      <div style="text-align:center;margin-bottom:14px;">
        <div style="display:inline-block;background:#fff3cd;color:#8a6d3b;font-size:11px;font-weight:800;text-transform:uppercase;padding:3px 10px;border-radius:999px;">Pre-order</div>
        <div style="font-family:monospace;font-weight:700;margin-top:6px;">${escapeHtml(invoice.preorder_number)}</div>
        ${invoice.customer?.name ? `<div style="font-size:12px;color:#666;">${escapeHtml(invoice.customer.name)}</div>` : ''}
      </div>

      <div style="border-top:1px dashed #ccc;border-bottom:1px dashed #ccc;padding:10px 0;margin-bottom:12px;">
        ${itemsHtml}
      </div>

      <div style="display:flex;justify-content:space-between;font-size:14px;padding:4px 0;">
        <span>Total</span><span style="font-weight:800;">${formatIDR(invoice.total_amount)}</span>
      </div>
      ${payment ? `
        <div style="display:flex;justify-content:space-between;background:#e8f7f0;border-radius:8px;padding:8px 10px;margin:6px 0;">
          <span style="font-weight:700;">Dibayar kali ini</span><span style="font-weight:800;">${formatIDR(payment.amount)}</span>
        </div>` : ''}
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;">
        <span>Sudah dibayar</span><span>${formatIDR(invoice.paid_amount)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;">
        <span>Sisa tagihan</span><span>${formatIDR(invoice.outstanding)}</span>
      </div>

      ${channels.length ? `
        <div style="margin-top:14px;border-top:1px dashed #ccc;padding-top:10px;">
          <div style="text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:6px;">Cara pembayaran</div>
          ${channelsHtml}
        </div>` : ''}

      ${invoice.footer_text ? `<div style="margin-top:14px;border-top:1px dashed #ccc;padding-top:10px;text-align:center;font-size:11px;color:#888;">${escapeHtml(invoice.footer_text)}</div>` : ''}
    </div>
  `;
}
