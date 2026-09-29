import { formatIDR } from './money';
import { formatDate, formatDateRange } from './date';

/**
 * 022-preorder-invoice-crud-overhaul (US5, FR-013, research.md Decision 5)
 * — builds the SAME visual content PreorderInvoiceModal.vue/
 * PreorderPaymentReceiptModal.vue already render (store header, items,
 * totals, payment terms, footer), as a plain HTML string, so bulk
 * download can rasterize N invoices sequentially without mounting N Vue
 * modal instances. Kept deliberately close to those components' markup —
 * this is the same document, rendered for a batch context, not a second
 * design.
 *
 * 024-invoice-layout-shipping-slip — format updated to match the modal:
 * no green "Pre-order" badge, "Pre-Order Invoice" title, bordered event
 * block instead of bg-brand, footer with default fallback, QR + bank
 * channels in two columns, bank account number with word-break.
 */
function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

export function buildInvoiceHtml(invoice, documentType = 'invoice') {
  const payment = documentType === 'payment_invoice' ? (invoice.payments ?? [])[invoice.payments?.length - 1] : null;
  const storeIdentity = invoice.store_identity;
  const channels = invoice.payment_channels ?? [];
  const qrChannels = channels.filter((c) => c.type === 'qr_ewallet');
  const bankChannels = channels.filter((c) => c.type === 'bank_transfer');

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

  const channelsHtml = bankChannels
    .map(
      (c) => `
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #ddd;">
          <span style="font-weight:700;">${escapeHtml(c.provider)}</span>
          <span style="font-family:monospace;word-break:break-all;">${escapeHtml(c.account_number || '')}</span>
        </div>`
    )
    .join('');

  const qrChannelsHtml = qrChannels
    .map(
      (c) => `
        <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:6px 0;border-bottom:1px dashed #ddd;">
          ${c.qr_image_url ? `<img src="${escapeHtml(c.qr_image_url)}" alt="${escapeHtml(c.provider)}" style="max-width:96px;max-height:96px;border:1px solid #ddd;border-radius:6px;object-fit:contain;" />` : ''}
          <span style="font-weight:700;font-size:11px;">${escapeHtml(c.provider)}</span>
          ${c.account_name ? `<span style="font-size:9px;color:#888;">${escapeHtml(c.account_name)}</span>` : ''}
        </div>`
    )
    .join('');

  const footerText = invoice.footer_text || 'Thank you for your order. Show this document at our booth for pickup or delivery.';

  // event_available_on_date is null both when no restriction was chosen AND
  // when it's 'both' days — the raw event_available_on tells them apart.
  const availableOnDisplay = invoice.event_available_on === 'both'
    ? formatDateRange(invoice.event_start_date, invoice.event_end_date)
    : invoice.event_available_on_date ? formatDate(invoice.event_available_on_date) : null;

  return `
    <div style="width:560px;padding:24px;background:#fff;font-family:Arial,sans-serif;color:#1a1a1a;font-size:13px;">
      ${storeIdentity ? `
        <div style="text-align:center;border-bottom:1px dashed #ccc;padding-bottom:14px;margin-bottom:14px;">
          ${storeIdentity.logo_url ? `<img src="${escapeHtml(storeIdentity.logo_url)}" alt="logo" style="height:96px;width:auto;max-width:260px;margin-bottom:4px;border-radius:6px;object-fit:contain;" />` : ''}
          <div style="font-size:17px;font-weight:800;">${escapeHtml(storeIdentity.name)}</div>
          ${storeIdentity.address ? `<div style="font-size:11px;color:#666;">${escapeHtml(storeIdentity.address)}</div>` : ''}
        </div>` : ''}

      <div style="text-align:center;margin-bottom:12px;">
        <div style="font-size:14px;font-weight:700;">Pre-Order Invoice</div>
        <div style="font-family:monospace;font-weight:700;margin-top:4px;font-size:15px;">${escapeHtml(invoice.preorder_number)}</div>
        ${invoice.customer?.name ? `<div style="font-size:12px;color:#666;">${escapeHtml(invoice.customer.name)}</div>` : ''}
      </div>

      ${invoice.event_name || invoice.event_location || availableOnDisplay ? `
      <div style="display:flex;gap:12px;margin-bottom:12px;">
        <div style="flex:1;text-align:center;border:1px solid #ddd;border-radius:8px;padding:10px;">
          ${invoice.event_name ? `<div style="font-size:13px;font-weight:700;">${escapeHtml(invoice.event_name)}</div>` : ''}
          ${invoice.event_location ? `<div style="font-size:11px;color:#888;">Lokasi: ${escapeHtml(invoice.event_location)}</div>` : ''}
          ${availableOnDisplay ? `<div style="font-size:11px;color:#888;">Tersedia: ${escapeHtml(availableOnDisplay)}</div>` : ''}
        </div>
        <div style="flex:1;text-align:center;border:1px solid #ddd;border-radius:8px;padding:10px;">
          <div style="font-family:monospace;font-weight:700;">${escapeHtml(invoice.preorder_number)}</div>
          <div style="font-size:11px;">${escapeHtml(documentType === 'payment_invoice' ? 'Payment Invoice' : 'Invoice')}</div>
        </div>
      </div>` : ''}

      <div style="border-top:1px dashed #ccc;border-bottom:1px dashed #ccc;padding:8px 0;margin-bottom:12px;">
        ${itemsHtml}
      </div>

      <div style="display:flex;justify-content:space-between;font-size:14px;padding:4px 0;">
        <span>Total</span><span style="font-weight:800;">${formatIDR(invoice.total_amount)}</span>
      </div>
      ${payment ? `
        <div style="display:flex;justify-content:space-between;border-radius:8px;padding:8px 10px;margin:6px 0;">
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
          <div style="display:flex;gap:12px;">
            ${qrChannels.length ? `<div style="flex:1;">
              <div style="text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:4px;">QRIS / e-wallet</div>
              ${qrChannelsHtml}
            </div>` : ''}
            ${bankChannels.length ? `<div style="flex:1;">
              <div style="text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:4px;">Transfer bank</div>
              ${channelsHtml}
            </div>` : ''}
          </div>
        </div>` : ''}

      <div style="margin-top:14px;border-top:1px dashed #ccc;padding-top:10px;text-align:center;font-size:11px;color:#888;">
        ${escapeHtml(footerText)}
      </div>
    </div>
  `;
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
