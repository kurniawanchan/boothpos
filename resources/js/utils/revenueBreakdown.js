import { parseMoney } from './money';

/**
 * 048 — baris pendapatan di layar laporan penjualan dan Dashboard: Net, Diskon tingkat order, Pajak, dan Bruto.
 * Angkanya DIAMBIL dari server (GET /reports/sales → totals, GET /dashboard/summary → revenue). Bruto dari server
 * = net − diskon tingkat order + pajak (sama dengan total struk); bila server tidak mengirim grand_total, dihitung
 * dengan rumus yang sama, bukan aturan lain.
 *
 * @param {{net_sales?: string, order_discount_total?: string, tax_total?: string, grand_total?: string}|null} revenue
 * @returns {{net: number, orderDiscount: number, tax: number, gross: number}} angka dalam rupiah
 */
export function revenueBreakdown(revenue) {
  const net = parseMoney(revenue?.net_sales);
  const orderDiscount = parseMoney(revenue?.order_discount_total);
  const tax = parseMoney(revenue?.tax_total);
  const gross = revenue?.grand_total != null ? parseMoney(revenue.grand_total) : net - orderDiscount + tax;

  return { net, orderDiscount, tax, gross };
}
